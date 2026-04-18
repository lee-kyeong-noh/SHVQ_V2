<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../dist_library/saas/IntegrationService.php';

try {
    $auth = new AuthService();
    $context = $auth->currentContext();

    if ($context === []) {
        ApiResponse::error('AUTH_REQUIRED', 'authentication required', 401);
        exit;
    }

    $roleLevel = (int)($context['role_level'] ?? 0);
    $todo = strtolower(trim((string)($_POST['todo'] ?? $_GET['todo'] ?? 'summary')));

    $serviceCode = trim((string)($_POST['service_code'] ?? $_GET['service_code'] ?? ''));
    if ($serviceCode === '' || $roleLevel < 1 || $roleLevel > 5) {
        $serviceCode = (string)($context['service_code'] ?? 'shvq');
    }

    /* tenant_id는 반드시 세션에서만 가져온다 (사용자 입력 신뢰 금지) */
    $tenantId = (int)($context['tenant_id'] ?? 0);

    $providerParam = trim((string)($_POST['provider'] ?? $_GET['provider'] ?? ''));
    $providers = [];
    if ($providerParam === '') {
        $providers = ['smartthings', 'onvif', 'tuya', 'iot', 'nvr'];
    } else {
        $providers = array_values(array_filter(array_map(
            static fn(string $v): string => strtolower(trim($v)),
            explode(',', $providerParam)
        ), static fn(string $v): bool => $v !== '' && $v !== 'all'));
    }

    $service = new IntegrationService(DbConnection::get());

    if ($todo === 'summary') {
        $summary = $service->summary($serviceCode, $tenantId, $providers);
        ApiResponse::success($summary, 'OK', 'iot integration summary loaded');
        exit;
    }

    if ($todo === 'account_list') {
        $status = (string)($_POST['status'] ?? $_GET['status'] ?? '');
        $page = (int)($_POST['page'] ?? $_GET['page'] ?? 1);
        $limit = (int)($_POST['limit'] ?? $_GET['limit'] ?? 20);
        $result = $service->accountList($serviceCode, $tenantId, $providers, $status, $page, $limit);
        ApiResponse::success($result, 'OK', 'iot provider accounts loaded');
        exit;
    }

    if ($todo === 'checkpoint_list') {
        $status = (string)($_POST['status'] ?? $_GET['status'] ?? '');
        $page = (int)($_POST['page'] ?? $_GET['page'] ?? 1);
        $limit = (int)($_POST['limit'] ?? $_GET['limit'] ?? 20);
        $result = $service->checkpointList($serviceCode, $tenantId, $providers, $status, $page, $limit);
        ApiResponse::success($result, 'OK', 'iot sync checkpoints loaded');
        exit;
    }

    if ($todo === 'error_queue_list') {
        $status = (string)($_POST['status'] ?? $_GET['status'] ?? '');
        $jobType = (string)($_POST['job_type'] ?? $_GET['job_type'] ?? '');
        $page = (int)($_POST['page'] ?? $_GET['page'] ?? 1);
        $limit = (int)($_POST['limit'] ?? $_GET['limit'] ?? 20);
        $result = $service->errorQueueList($serviceCode, $tenantId, $providers, $status, $jobType, $page, $limit);
        ApiResponse::success($result, 'OK', 'iot error queue loaded');
        exit;
    }

    /* ════════════════════════════════════════
       프론트(views/saas/facility/iot.php) 호환 todo
       ════════════════════════════════════════ */

    $db = DbConnection::get();

    /* ── 헬퍼: 테이블 존재 여부 ── */
    $tableExists = static function (string $name) use ($db): bool {
        static $cache = [];
        $key = strtolower($name);
        if (isset($cache[$key])) return $cache[$key];
        $st = $db->prepare("SELECT CASE WHEN OBJECT_ID(:t, 'U') IS NULL THEN 0 ELSE 1 END AS yn");
        $st->execute([':t' => $name]);
        $cache[$key] = (int)$st->fetchColumn() === 1;
        return $cache[$key];
    };

    /* ── 헬퍼: 컬럼 존재 여부 ── */
    $columnExists = static function (string $table, string $column) use ($db, $tableExists): bool {
        static $cache = [];
        $key = strtolower($table . '.' . $column);
        if (isset($cache[$key])) return $cache[$key];
        if (!$tableExists($table)) {
            $cache[$key] = false;
            return false;
        }
        $st = $db->prepare("SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND COLUMN_NAME = ?");
        $st->execute([$table, $column]);
        $cache[$key] = (int)$st->fetchColumn() > 0;
        return $cache[$key];
    };

    $tryFetchRows = static function (string $sql, array $params = []) use ($db): array {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        } catch (Throwable) {
            return [];
        }
    };

    $appendCapabilityFilter = static function (array &$where, array &$params, string $columnSql, string $capability): void {
        $capability = strtolower(trim($capability));
        if ($capability === '') {
            return;
        }
        if (str_contains($capability, '.') || str_contains($capability, ':')) {
            $where[] = "LOWER(ISNULL({$columnSql}, '')) = ?";
            $params[] = $capability;
            return;
        }
        $where[] = "(
            LOWER(ISNULL({$columnSql}, '')) = ?
            OR LOWER(ISNULL({$columnSql}, '')) LIKE ?
            OR LOWER(ISNULL({$columnSql}, '')) LIKE ?
        )";
        $params[] = $capability;
        $params[] = '%.' . $capability;
        $params[] = '%:' . $capability;
    };

    $base64UrlDecode = static function (string $value): string {
        $value = strtr(trim($value), '-_', '+/');
        if ($value === '') {
            return '';
        }
        $padLen = strlen($value) % 4;
        if ($padLen > 0) {
            $value .= str_repeat('=', 4 - $padLen);
        }
        $decoded = base64_decode($value, true);
        return is_string($decoded) ? $decoded : '';
    };

    $credentialCryptoKey = static function (): string {
        $candidates = [
            getenv('IOT_CREDENTIAL_KEY'),
            getenv('INT_CREDENTIAL_KEY'),
            getenv('APP_KEY'),
            getenv('SECRET_KEY'),
        ];
        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                return hash('sha256', $candidate, true);
            }
        }
        return hash('sha256', __DIR__ . '|shvq_v2_iot_credential_key', true);
    };

    $decryptSecretValue = static function (string $value) use ($base64UrlDecode, $credentialCryptoKey): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $prefix = 'enc:v1:';
        if (!str_starts_with($value, $prefix)) {
            return $value;
        }
        if (!function_exists('openssl_decrypt')) {
            return '';
        }

        $raw = $base64UrlDecode(substr($value, strlen($prefix)));
        if ($raw === '' || strlen($raw) <= 48) {
            return '';
        }
        $iv = substr($raw, 0, 16);
        $mac = substr($raw, 16, 32);
        $cipherRaw = substr($raw, 48);
        if ($iv === '' || $mac === '' || $cipherRaw === '') {
            return '';
        }

        $key = $credentialCryptoKey();
        $expectedMac = hash_hmac('sha256', $iv . $cipherRaw, $key, true);
        if (!hash_equals($mac, $expectedMac)) {
            return '';
        }
        $plain = openssl_decrypt($cipherRaw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return is_string($plain) ? $plain : '';
    };

    $buildControlMeta = static function (string $capabilityRaw, string $deviceType, string $lastStateRaw): array {
        $blob = strtolower($capabilityRaw);
        $type = strtolower(trim($deviceType));
        $last = strtolower(trim($lastStateRaw));

        $hasSwitch = str_contains($blob, 'switch') || in_array($type, ['switch', 'light', 'plug', 'outlet'], true);
        $hasLock = str_contains($blob, 'lock') || in_array($type, ['doorlock', 'lock', 'smartlock'], true);
        $hasOpenClose = str_contains($blob, 'doorcontrol')
            || str_contains($blob, 'windowshade')
            || str_contains($blob, 'garagedoorcontrol')
            || in_array($type, ['door', 'garage', 'curtain', 'blind', 'windowshade'], true);

        $cmdMap = [];
        if ($hasSwitch) {
            $cmdMap['on'] = true;
            $cmdMap['off'] = true;
        }
        if ($hasLock) {
            $cmdMap['lock'] = true;
            $cmdMap['unlock'] = true;
        }
        if ($hasOpenClose) {
            $cmdMap['open'] = true;
            $cmdMap['close'] = true;
        }
        $cmds = array_keys($cmdMap);
        sort($cmds);

        $allowedStates = ['on', 'off', 'open', 'close', 'lock', 'unlock'];
        $lastState = in_array($last, $allowedStates, true) ? $last : 'unknown';

        return [
            'is_ctrl' => $cmds !== [],
            'cmds' => $cmds,
            'last_state' => $lastState,
        ];
    };

    /* ── 헬퍼: 장치 목록 조회 ── */
    $fetchDevices = static function (int $tid, string $svcCode, string $platform = '', string $status = '', bool $includeHidden = false) use ($db, $tableExists, $columnExists, $buildControlMeta): array {
        if (!$tableExists('Tb_IntDevice')) return [];

        $idExpr = $columnExists('Tb_IntDevice', 'device_id')
            ? 'device_id'
            : ($columnExists('Tb_IntDevice', 'external_id') ? 'external_id' : "CAST('' AS NVARCHAR(120))");
        $nameExpr = $columnExists('Tb_IntDevice', 'device_name')
            ? 'device_name'
            : ($columnExists('Tb_IntDevice', 'device_label') ? 'device_label' : $idExpr);
        $typeExpr = $columnExists('Tb_IntDevice', 'device_type')
            ? 'device_type'
            : "CAST('device' AS NVARCHAR(50))";
        $platformExpr = $columnExists('Tb_IntDevice', 'provider')
            ? 'provider'
            : "CAST('iot' AS NVARCHAR(30))";
        $adapterExpr = $columnExists('Tb_IntDevice', 'adapter')
            ? 'adapter'
            : $platformExpr;
        $providerAccountExpr = $columnExists('Tb_IntDevice', 'provider_account_idx')
            ? 'provider_account_idx'
            : 'CAST(0 AS INT)';
        $roomExpr = $columnExists('Tb_IntDevice', 'room_name')
            ? 'room_name'
            : "CAST('' AS NVARCHAR(200))";
        $locationExpr = $columnExists('Tb_IntDevice', 'location_name')
            ? 'location_name'
            : "CAST('' AS NVARCHAR(200))";
        $statusExpr = $columnExists('Tb_IntDevice', 'is_active')
            ? "CASE WHEN is_active=1 THEN 'online' ELSE 'offline' END"
            : "'unknown'";
        $manufacturerExpr = $columnExists('Tb_IntDevice', 'manufacturer')
            ? 'manufacturer'
            : "CAST('' AS NVARCHAR(100))";
        $modelExpr = $columnExists('Tb_IntDevice', 'model')
            ? 'model'
            : "CAST('' AS NVARCHAR(100))";
        $firmwareExpr = $columnExists('Tb_IntDevice', 'firmware_version')
            ? 'firmware_version'
            : "CAST('' AS NVARCHAR(100))";
        $lastStateExpr = $columnExists('Tb_IntDevice', 'last_state')
            ? 'last_state'
            : "CAST('' AS NVARCHAR(100))";
        $isHiddenExpr = $columnExists('Tb_IntDevice', 'is_hidden')
            ? 'is_hidden'
            : 'CAST(0 AS BIT)';
        $sortOrderExpr = $columnExists('Tb_IntDevice', 'sort_order')
            ? 'sort_order'
            : 'CAST(9999 AS INT)';
        $capabilityExpr = $columnExists('Tb_IntDevice', 'capability_json')
            ? 'capability_json'
            : ($columnExists('Tb_IntDevice', 'capabilities_json')
                ? 'capabilities_json'
                : ($columnExists('Tb_IntDevice', 'raw_json')
                    ? 'raw_json'
                    : "CAST('' AS NVARCHAR(MAX))"));
        $lastEventBase = $columnExists('Tb_IntDevice', 'last_event_at')
            ? 'last_event_at'
            : ($columnExists('Tb_IntDevice', 'last_sync_at')
                ? 'last_sync_at'
                : ($columnExists('Tb_IntDevice', 'updated_at') ? 'updated_at' : 'GETDATE()'));
        $updatedBase = $columnExists('Tb_IntDevice', 'updated_at')
            ? 'updated_at'
            : ($columnExists('Tb_IntDevice', 'created_at') ? 'created_at' : 'GETDATE()');
        $orderBy = '';
        if ($columnExists('Tb_IntDevice', 'sort_order')) {
            $orderBy = 'ISNULL(sort_order, 9999) ASC, ';
        }
        $orderBy .= $columnExists('Tb_IntDevice', 'device_label')
            ? 'device_label ASC'
            : ($columnExists('Tb_IntDevice', 'device_name')
                ? 'device_name ASC'
                : ($columnExists('Tb_IntDevice', 'idx') ? 'idx DESC' : '1'));

        $where = [];
        $params = [];
        if ($columnExists('Tb_IntDevice', 'service_code')) {
            $where[] = 'service_code = ?';
            $params[] = $svcCode;
        }
        if ($columnExists('Tb_IntDevice', 'tenant_id')) {
            $where[] = 'tenant_id = ?';
            $params[] = $tid;
        }
        if ($columnExists('Tb_IntDevice', 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0) = 0';
        }
        if (!$includeHidden && $columnExists('Tb_IntDevice', 'is_hidden')) {
            $where[] = 'ISNULL(is_hidden,0) = 0';
        }
        if ($platform !== '' && $columnExists('Tb_IntDevice', 'provider')) {
            $where[] = 'LOWER(provider) = ?';
            $params[] = strtolower($platform);
        }
        if ($status === 'online' && $columnExists('Tb_IntDevice', 'is_active')) {
            $where[] = 'is_active = 1';
        }
        if ($status === 'offline' && $columnExists('Tb_IntDevice', 'is_active')) {
            $where[] = '(is_active = 0 OR is_active IS NULL)';
        }

        $sql = "SELECT
                    idx,
                    {$idExpr} AS device_id,
                    {$nameExpr} AS name,
                    {$typeExpr} AS type,
                    {$platformExpr} AS platform,
                    {$adapterExpr} AS adapter,
                    {$providerAccountExpr} AS provider_account_idx,
                    {$roomExpr} AS room,
                    {$locationExpr} AS location,
                    {$statusExpr} AS status,
                    {$manufacturerExpr} AS manufacturer,
                    {$modelExpr} AS model,
                    {$firmwareExpr} AS firmware,
                    {$isHiddenExpr} AS is_hidden,
                    {$sortOrderExpr} AS sort_order,
                    {$lastStateExpr} AS last_state,
                    {$capabilityExpr} AS capability_raw,
                    CONVERT(VARCHAR(19), {$lastEventBase}, 120) AS last_event,
                    CONVERT(VARCHAR(19), {$updatedBase}, 120) AS updated_at
                FROM Tb_IntDevice
                WHERE " . ($where === [] ? '1=1' : implode(' AND ', $where)) . "
                ORDER BY {$orderBy}";
        $st = $db->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return [];
        }

        $mapCountByDeviceIdx = [];
        if ($tableExists('Tb_IntDeviceMap') && $columnExists('Tb_IntDeviceMap', 'device_idx')) {
            $idxList = [];
            foreach ($rows as $row) {
                $idx = (int)($row['idx'] ?? 0);
                if ($idx > 0) {
                    $idxList[$idx] = true;
                }
            }
            $idxList = array_keys($idxList);
            if ($idxList !== []) {
                $holders = implode(',', array_fill(0, count($idxList), '?'));
                $mapWhere = ["device_idx IN ({$holders})"];
                $mapParams = array_values($idxList);
                if ($columnExists('Tb_IntDeviceMap', 'service_code')) {
                    $mapWhere[] = 'service_code = ?';
                    $mapParams[] = $svcCode;
                }
                if ($columnExists('Tb_IntDeviceMap', 'tenant_id')) {
                    $mapWhere[] = 'tenant_id = ?';
                    $mapParams[] = $tid;
                }
                if ($columnExists('Tb_IntDeviceMap', 'is_deleted')) {
                    $mapWhere[] = 'ISNULL(is_deleted,0)=0';
                }

                $mapSql = "SELECT device_idx, COUNT(1) AS cnt
                           FROM Tb_IntDeviceMap
                           WHERE " . implode(' AND ', $mapWhere) . "
                           GROUP BY device_idx";
                $mapStmt = $db->prepare($mapSql);
                $mapStmt->execute($mapParams);
                $mapRows = $mapStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($mapRows as $m) {
                    $mapCountByDeviceIdx[(int)($m['device_idx'] ?? 0)] = (int)($m['cnt'] ?? 0);
                }
            }
        }

        $items = [];
        foreach ($rows as $row) {
            $idx = (int)($row['idx'] ?? 0);
            $deviceType = strtolower(trim((string)($row['type'] ?? 'device')));
            if ($deviceType === '') {
                $deviceType = 'device';
            }
            $deviceName = trim((string)($row['name'] ?? ''));
            if ($deviceName === '') {
                $deviceName = (string)($row['device_id'] ?? '');
            }
            $adapter = strtolower(trim((string)($row['adapter'] ?? '')));
            if ($adapter === '') {
                $adapter = strtolower(trim((string)($row['platform'] ?? 'iot')));
            }

            $meta = $buildControlMeta(
                (string)($row['capability_raw'] ?? ''),
                $deviceType,
                (string)($row['last_state'] ?? '')
            );

            $row['device_type'] = $deviceType;
            $row['device_name'] = $deviceName;
            $row['adapter'] = $adapter;
            $row['is_ctrl'] = (bool)($meta['is_ctrl'] ?? false);
            $row['map_count'] = (int)($mapCountByDeviceIdx[$idx] ?? 0);
            $row['is_hidden'] = (int)($row['is_hidden'] ?? 0);
            $row['sort_order'] = (int)($row['sort_order'] ?? 9999);
            $row['last_state'] = (string)($meta['last_state'] ?? 'unknown');
            $row['cmds'] = is_array($meta['cmds'] ?? null) ? $meta['cmds'] : [];

            unset($row['capability_raw']);
            $items[] = $row;
        }

        return $items;
    };

    $findTokenInArray = static function ($payload) use (&$findTokenInArray): string {
        if (!is_array($payload)) {
            return '';
        }
        foreach (['access_token', 'accessToken', 'token', 'bearer_token', 'bearer', 'pat'] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $value = trim((string)$payload[$key]);
            if ($value !== '') {
                return $value;
            }
        }
        foreach ($payload as $value) {
            if (is_array($value)) {
                $found = $findTokenInArray($value);
                if ($found !== '') {
                    return $found;
                }
            }
        }
        return '';
    };

    $resolveSmartThingsToken = static function (int $providerAccountIdx, string $svcCode, int $tid) use ($db, $tableExists, $columnExists, $decryptSecretValue, $findTokenInArray): array {
        $accountIdx = $providerAccountIdx;
        $accountRawJson = '';
        $svcCodeNorm = strtolower(trim($svcCode));
        $debug = [
            'requested_account_idx' => $providerAccountIdx,
            'resolved_account_idx' => $providerAccountIdx,
            'provider_token_checked' => false,
        ];

        if ($tableExists('Tb_IntProviderAccount')) {
            $where = [];
            $params = [];
            if ($columnExists('Tb_IntProviderAccount', 'service_code') && $svcCodeNorm !== '') {
                $where[] = 'LOWER(LTRIM(RTRIM(service_code))) = ?';
                $params[] = $svcCodeNorm;
            }
            if ($columnExists('Tb_IntProviderAccount', 'tenant_id')) {
                $where[] = 'tenant_id = ?';
                $params[] = $tid;
            }
            if ($columnExists('Tb_IntProviderAccount', 'provider')) {
                $where[] = "LOWER(LTRIM(RTRIM(provider))) IN ('smartthings','st')";
            }
            if ($columnExists('Tb_IntProviderAccount', 'status')) {
                $where[] = "status = 'ACTIVE'";
            }
            if ($columnExists('Tb_IntProviderAccount', 'is_deleted')) {
                $where[] = 'ISNULL(is_deleted,0)=0';
            }
            if ($accountIdx > 0) {
                $where[] = 'idx = ?';
                $params[] = $accountIdx;
            }

            $rawExpr = $columnExists('Tb_IntProviderAccount', 'raw_json')
                ? 'raw_json'
                : "CAST('' AS NVARCHAR(MAX))";
            $orderSql = $columnExists('Tb_IntProviderAccount', 'is_primary')
                ? 'is_primary DESC, idx DESC'
                : 'idx DESC';
            $sql = "SELECT TOP 1 idx, {$rawExpr} AS raw_json
                    FROM Tb_IntProviderAccount
                    WHERE " . ($where !== [] ? implode(' AND ', $where) : '1=1') . "
                    ORDER BY {$orderSql}";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $acc = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            /* service_code/tenant 조건 불일치가 있어도 device의 provider_account_idx가 명확하면 idx 기반 fallback */
            if ($acc === [] && $accountIdx > 0) {
                $fallbackWhere = ['idx = ?'];
                $fallbackParams = [$accountIdx];
                if ($columnExists('Tb_IntProviderAccount', 'provider')) {
                    $fallbackWhere[] = "LOWER(LTRIM(RTRIM(provider))) IN ('smartthings','st')";
                }
                if ($columnExists('Tb_IntProviderAccount', 'status')) {
                    $fallbackWhere[] = "status = 'ACTIVE'";
                }
                if ($columnExists('Tb_IntProviderAccount', 'is_deleted')) {
                    $fallbackWhere[] = 'ISNULL(is_deleted,0)=0';
                }
                $fallbackSql = "SELECT TOP 1 idx, {$rawExpr} AS raw_json
                                FROM Tb_IntProviderAccount
                                WHERE " . implode(' AND ', $fallbackWhere) . "
                                ORDER BY {$orderSql}";
                $fallbackStmt = $db->prepare($fallbackSql);
                $fallbackStmt->execute($fallbackParams);
                $acc = $fallbackStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                if ($acc !== []) {
                    $debug['account_lookup_fallback'] = true;
                }
            }

            if ($acc !== []) {
                $accountIdx = (int)($acc['idx'] ?? $accountIdx);
                $accountRawJson = (string)($acc['raw_json'] ?? '');
            }
        }
        $debug['resolved_account_idx'] = $accountIdx;

        if ($accountIdx > 0 && $tableExists('Tb_IntCredential')) {
            $credWhere = ['provider_account_idx = ?'];
            $credParams = [$accountIdx];
            if ($columnExists('Tb_IntCredential', 'status')) {
                $credWhere[] = "status = 'ACTIVE'";
            }
            $credSql = 'SELECT secret_type, secret_value_enc
                        FROM Tb_IntCredential
                        WHERE ' . implode(' AND ', $credWhere) . '
                        ORDER BY idx DESC';
            $credStmt = $db->prepare($credSql);
            $credStmt->execute($credParams);
            $credRows = $credStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($credRows as $cred) {
                $type = strtolower(trim((string)($cred['secret_type'] ?? '')));
                if (!in_array($type, ['access_token', 'token', 'bearer_token', 'bearer'], true)) {
                    continue;
                }
                $secret = $decryptSecretValue((string)($cred['secret_value_enc'] ?? ''));
                if ($secret !== '') {
                    return ['token' => $secret, 'account_idx' => $accountIdx, 'source' => 'credential', 'debug' => $debug];
                }
            }
        }

        if ($tableExists('Tb_IntProviderToken')) {
            $debug['provider_token_checked'] = true;
            $tokenExpr = $columnExists('Tb_IntProviderToken', 'access_token')
                ? 'access_token'
                : ($columnExists('Tb_IntProviderToken', 'token')
                    ? 'token'
                    : ($columnExists('Tb_IntProviderToken', 'token_value')
                        ? 'token_value'
                        : "CAST('' AS NVARCHAR(MAX))"));
            $tokenAccountExpr = $columnExists('Tb_IntProviderToken', 'provider_account_idx')
                ? 'provider_account_idx'
                : 'CAST(0 AS INT)';

            $baseWhere = [];
            $baseParams = [];
            if ($columnExists('Tb_IntProviderToken', 'provider')) {
                $baseWhere[] = "LOWER(LTRIM(RTRIM(provider))) IN ('smartthings','st')";
            }
            if ($columnExists('Tb_IntProviderToken', 'status')) {
                $baseWhere[] = "status = 'ACTIVE'";
            }
            $orderParts = [];
            if ($columnExists('Tb_IntProviderToken', 'updated_at')) {
                $orderParts[] = 'updated_at DESC';
            }
            if ($columnExists('Tb_IntProviderToken', 'idx')) {
                $orderParts[] = 'idx DESC';
            }
            $orderSql = $orderParts !== [] ? implode(', ', $orderParts) : 'idx DESC';

            $tokenChecks = [];
            $strictWhere = $baseWhere;
            $strictParams = $baseParams;
            if ($columnExists('Tb_IntProviderToken', 'service_code') && $svcCodeNorm !== '') {
                $strictWhere[] = 'LOWER(LTRIM(RTRIM(service_code))) = ?';
                $strictParams[] = $svcCodeNorm;
            }
            if ($columnExists('Tb_IntProviderToken', 'tenant_id')) {
                $strictWhere[] = 'tenant_id = ?';
                $strictParams[] = $tid;
            }
            if ($accountIdx > 0 && $columnExists('Tb_IntProviderToken', 'provider_account_idx')) {
                $strictWhere[] = 'ISNULL(provider_account_idx,0) = ?';
                $strictParams[] = $accountIdx;
            }
            $tokenChecks[] = ['where' => $strictWhere, 'params' => $strictParams, 'source' => 'provider_token'];

            if ($accountIdx > 0 && $columnExists('Tb_IntProviderToken', 'provider_account_idx')) {
                $fallbackWhere = $baseWhere;
                $fallbackParams = $baseParams;
                $fallbackWhere[] = 'ISNULL(provider_account_idx,0) = ?';
                $fallbackParams[] = $accountIdx;
                $tokenChecks[] = ['where' => $fallbackWhere, 'params' => $fallbackParams, 'source' => 'provider_token_account_fallback'];

                /* 상태/서비스코드 불일치 데이터가 있어도 account_idx 기준으로 마지막 토큰을 확인 */
                $looseWhere = ['ISNULL(provider_account_idx,0) = ?'];
                $looseParams = [$accountIdx];
                $tokenChecks[] = ['where' => $looseWhere, 'params' => $looseParams, 'source' => 'provider_token_account_loose'];
            }
            $debug['token_checks'] = array_values(array_map(
                static fn(array $item): string => (string)($item['source'] ?? ''),
                $tokenChecks
            ));

            foreach ($tokenChecks as $tokenCheck) {
                $tokenSql = "SELECT TOP 5
                                {$tokenExpr} AS access_token,
                                {$tokenAccountExpr} AS provider_account_idx
                             FROM Tb_IntProviderToken
                             WHERE " . (($tokenCheck['where'] ?? []) !== [] ? implode(' AND ', (array)$tokenCheck['where']) : '1=1') . "
                             ORDER BY {$orderSql}";
                $tokenStmt = $db->prepare($tokenSql);
                $tokenStmt->execute((array)($tokenCheck['params'] ?? []));
                $tokenRows = $tokenStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($tokenRows as $tokenRow) {
                    $rawToken = trim((string)($tokenRow['access_token'] ?? ''));
                    if ($rawToken === '') {
                        continue;
                    }
                    $resolvedToken = trim($decryptSecretValue($rawToken));
                    if ($resolvedToken === '') {
                        $resolvedToken = $rawToken;
                    }
                    if ($resolvedToken !== '') {
                        $resolvedAccountIdx = (int)($tokenRow['provider_account_idx'] ?? $accountIdx);
                        if ($resolvedAccountIdx > 0) {
                            $accountIdx = $resolvedAccountIdx;
                            $debug['resolved_account_idx'] = $resolvedAccountIdx;
                        }
                        return [
                            'token' => $resolvedToken,
                            'account_idx' => $accountIdx,
                            'source' => (string)($tokenCheck['source'] ?? 'provider_token'),
                            'debug' => $debug,
                        ];
                    }
                }
            }
        }

        if ($accountRawJson !== '') {
            $decoded = json_decode($accountRawJson, true);
            if (is_array($decoded)) {
                $token = $findTokenInArray($decoded);
                if ($token !== '') {
                    return ['token' => $token, 'account_idx' => $accountIdx, 'source' => 'account_raw_json', 'debug' => $debug];
                }
            }
        }

        return ['token' => '', 'account_idx' => $accountIdx, 'source' => 'none', 'debug' => $debug];
    };

    $smartThingsRequest = static function (string $method, string $url, array $body, string $token): array {
        $method = strtoupper(trim($method));
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            $payload = '{}';
        }
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
            $raw = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            $bodyText = is_string($raw) ? $raw : '';
            $decoded = json_decode($bodyText, true);
            return [
                'ok' => $status >= 200 && $status < 300 && $error === '',
                'status' => $status,
                'body' => $bodyText,
                'json' => is_array($decoded) ? $decoded : [],
                'error' => $error,
            ];
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $payload,
                'timeout' => 12,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        $bodyText = is_string($raw) ? $raw : '';
        $status = 0;
        $responseHeaders = [];
        if (function_exists('http_get_last_response_headers')) {
            $hdr = http_get_last_response_headers();
            if (is_array($hdr)) {
                $responseHeaders = $hdr;
            }
        } elseif (isset($GLOBALS['http_response_header']) && is_array($GLOBALS['http_response_header'])) {
            $responseHeaders = $GLOBALS['http_response_header'];
        }
        foreach ($responseHeaders as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string)$line, $m) === 1) {
                $status = (int)$m[1];
                break;
            }
        }
        $decoded = json_decode($bodyText, true);
        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $bodyText,
            'json' => is_array($decoded) ? $decoded : [],
            'error' => '',
        ];
    };

    /* ── dashboard (GET) ── */
    if ($todo === 'dashboard') {
        try {
            /* summary 데이터 재활용 (검증된 코드) */
            $summary = $service->summary($serviceCode, $tenantId);
            $devices = $fetchDevices($tenantId, $serviceCode);
            $deviceTotal = count($devices);
            $deviceOnline = 0;
            $doorlockCount = 0;
            $controllableCount = 0;
            $nonControllableCount = 0;
            $locationSet = [];
            $roomSet = [];
            $deviceTypeCounts = [];
            foreach ($devices as $dev) {
                $devStatus = strtolower((string)($dev['status'] ?? ''));
                $devType = strtolower(trim((string)($dev['device_type'] ?? $dev['type'] ?? 'device')));
                if ($devType === '') {
                    $devType = 'device';
                }
                if ($devStatus === 'online') {
                    $deviceOnline++;
                }
                if (in_array($devType, ['doorlock', 'lock', 'smartlock'], true)) {
                    $doorlockCount++;
                }
                $deviceTypeCounts[$devType] = (int)($deviceTypeCounts[$devType] ?? 0) + 1;

                $isCtrl = (bool)($dev['is_ctrl'] ?? false);
                if ($isCtrl) {
                    $controllableCount++;
                } else {
                    $nonControllableCount++;
                }

                $locationName = trim((string)($dev['location'] ?? ''));
                if ($locationName !== '') {
                    $locationSet[$locationName] = true;
                }
                $roomName = trim((string)($dev['room'] ?? ''));
                if ($roomName !== '') {
                    $roomSet[$roomName] = true;
                }
            }
            if ($deviceTotal === 0) {
                $deviceTotal = (int)($summary['devices_total'] ?? 0);
                $deviceOnline = (int)($summary['devices_active_total'] ?? 0);
                $nonControllableCount = max(0, $deviceTotal - $controllableCount);
            }
            ksort($deviceTypeCounts);

            /* 플랫폼 연결 상태 */
            $stConnected = false; $tuyaConnected = false;
            $breakdown = $summary['provider_breakdown'] ?? [];
            foreach ($breakdown as $pb) {
                $pv = strtolower((string)($pb['provider'] ?? ''));
                $act = (int)($pb['accounts_active'] ?? 0);
                if ($act > 0 && strpos($pv, 'smart') !== false) $stConnected = true;
                if ($act > 0 && strpos($pv, 'tuya') !== false) $tuyaConnected = true;
            }

            /* 최근 이벤트 */
            $recentEvents = [];
            try {
                $cpResult = $service->checkpointList($serviceCode, $tenantId, $providers, '', 1, 10);
                foreach (($cpResult['items'] ?? []) as $cp) {
                    $scope = (string)($cp['sync_scope'] ?? $cp['sync_type'] ?? '');
                    $message = (string)($cp['status'] ?? '');
                    if ($scope !== '') {
                        $message .= ($message !== '' ? ': ' : '') . $scope;
                    }
                    if (trim((string)($cp['last_error'] ?? '')) !== '') {
                        $message .= ' - ' . (string)$cp['last_error'];
                    }
                    $recentEvents[] = [
                        'time'    => (string)($cp['updated_at'] ?? $cp['last_success_at'] ?? ''),
                        'device'  => (string)($cp['provider'] ?? ''),
                        'message' => $message,
                    ];
                }
            } catch (Throwable $ignore) {}

            ApiResponse::success([
                'total'    => $deviceTotal,
                'online'   => $deviceOnline,
                'offline'  => max(0, $deviceTotal - $deviceOnline),
                'doorlock' => $doorlockCount,
                'controllable' => $controllableCount,
                'non_controllable' => $nonControllableCount,
                'location_count' => count($locationSet),
                'room_count' => count($roomSet),
                'device_type_counts' => $deviceTypeCounts,
                'smartthings_connected' => $stConnected,
                'tuya_connected'        => $tuyaConnected,
                'recent_events'         => $recentEvents,
            ]);
        } catch (Throwable $e) {
            ApiResponse::success([
                'total' => 0, 'online' => 0, 'offline' => 0, 'doorlock' => 0,
                'controllable' => 0, 'non_controllable' => 0,
                'location_count' => 0, 'room_count' => 0, 'device_type_counts' => [],
                'smartthings_connected' => false, 'tuya_connected' => false,
                'recent_events' => [],
                '_debug' => shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : null,
            ]);
        }
        exit;
    }

    /* ── device_list (GET) ── */
    if ($todo === 'device_list') {
        try {
            $platform = strtolower(trim((string)($_GET['platform'] ?? '')));
            $status   = strtolower(trim((string)($_GET['status'] ?? '')));
            $devices  = $fetchDevices($tenantId, $serviceCode, $platform, $status);
            ApiResponse::success(['items' => $devices, 'count' => count($devices)]);
        } catch (Throwable $e) {
            ApiResponse::success(['items' => [], 'count' => 0,
                '_debug' => shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : null]);
        }
        exit;
    }

    /* ── space_list (GET) ── */
    if ($todo === 'space_list') {
        try {
            $devices = $fetchDevices($tenantId, $serviceCode);
            $grouped = [];
            foreach ($devices as $d) {
                $spaceName = trim((string)($d['room'] ?? ''));
                if ($spaceName === '') {
                    $spaceName = trim((string)($d['location'] ?? ''));
                }
                if ($spaceName === '') {
                    $spaceName = '미지정';
                }
                if (!isset($grouped[$spaceName])) {
                    $grouped[$spaceName] = ['name' => $spaceName, 'devices' => []];
                }
                $grouped[$spaceName]['devices'][] = [
                    'device_id' => (string)($d['device_id'] ?? ''),
                    'name' => (string)($d['name'] ?? ''),
                    'type' => (string)($d['type'] ?? ''),
                    'status' => (string)($d['status'] ?? 'unknown'),
                ];
            }
            $spaces = array_values($grouped);
            ApiResponse::success(['items' => $spaces, 'count' => count($spaces)]);
        } catch (Throwable $e) {
            ApiResponse::success(['items' => [], 'count' => 0,
                '_debug' => shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : null]);
        }
        exit;
    }

    /* ── event_log (GET) ── */
    if ($todo === 'event_log') {
        try {
            $limit = max(1, min(300, (int)($_GET['limit'] ?? 100)));
            $platform = strtolower(trim((string)($_GET['platform'] ?? '')));
            $capabilityFilter = strtolower(trim((string)($_GET['capability'] ?? '')));
            $logType = strtolower(trim((string)($_GET['log_type'] ?? '')));
            if (!in_array($logType, ['', 'event', 'command'], true)) {
                $logType = '';
            }

            $items = [];

            if ($logType !== 'command') {
                $eventItems = [];

                if ($tableExists('Tb_IntEventLog')) {
                    $timeExpr = $columnExists('Tb_IntEventLog', 'event_timestamp')
                        ? 'event_timestamp'
                        : ($columnExists('Tb_IntEventLog', 'created_at')
                            ? 'created_at'
                            : ($columnExists('Tb_IntEventLog', 'updated_at') ? 'updated_at' : 'GETDATE()'));
                    $deviceIdExpr = $columnExists('Tb_IntEventLog', 'device_id')
                        ? 'device_id'
                        : ($columnExists('Tb_IntEventLog', 'device_external_id')
                            ? 'device_external_id'
                            : ($columnExists('Tb_IntEventLog', 'external_id')
                                ? 'external_id'
                                : "CAST('' AS NVARCHAR(120))"));
                    $deviceLabelExpr = $columnExists('Tb_IntEventLog', 'device_label')
                        ? 'device_label'
                        : ($columnExists('Tb_IntEventLog', 'device_name')
                            ? 'device_name'
                            : "CAST('' AS NVARCHAR(200))");
                    $capabilityExpr = $columnExists('Tb_IntEventLog', 'capability')
                        ? 'capability'
                        : ($columnExists('Tb_IntEventLog', 'event_capability')
                            ? 'event_capability'
                            : "CAST('' AS NVARCHAR(120))");
                    $attributeExpr = $columnExists('Tb_IntEventLog', 'attribute')
                        ? 'attribute'
                        : "CAST('' AS NVARCHAR(120))";
                    $valueExpr = $columnExists('Tb_IntEventLog', 'event_value')
                        ? 'event_value'
                        : ($columnExists('Tb_IntEventLog', 'value')
                            ? 'value'
                            : ($columnExists('Tb_IntEventLog', 'message')
                                ? 'message'
                                : "CAST('' AS NVARCHAR(1000))"));
                    $messageExpr = $columnExists('Tb_IntEventLog', 'message')
                        ? 'message'
                        : ($columnExists('Tb_IntEventLog', 'event_message')
                            ? 'event_message'
                            : "CAST('' AS NVARCHAR(1000))");
                    $providerExpr = $columnExists('Tb_IntEventLog', 'provider')
                        ? 'provider'
                        : "CAST('' AS NVARCHAR(30))";

                    $where = [];
                    $params = [];
                    if ($columnExists('Tb_IntEventLog', 'service_code')) {
                        $where[] = 'service_code = ?';
                        $params[] = $serviceCode;
                    }
                    if ($columnExists('Tb_IntEventLog', 'tenant_id')) {
                        $where[] = 'tenant_id = ?';
                        $params[] = $tenantId;
                    }
                    if ($columnExists('Tb_IntEventLog', 'is_deleted')) {
                        $where[] = 'ISNULL(is_deleted,0)=0';
                    }
                    if ($platform !== '' && $platform !== 'all' && $columnExists('Tb_IntEventLog', 'provider')) {
                        $where[] = 'LOWER(provider)=?';
                        $params[] = $platform;
                    }
                    if ($columnExists('Tb_IntEventLog', 'log_type')) {
                        $where[] = "LOWER(ISNULL(log_type,'')) IN ('event','')";
                    } elseif ($columnExists('Tb_IntEventLog', 'lifecycle')) {
                        $where[] = "UPPER(ISNULL(lifecycle,''))='EVENT'";
                    }
                    if ($capabilityFilter !== '' && ($columnExists('Tb_IntEventLog', 'capability') || $columnExists('Tb_IntEventLog', 'event_capability'))) {
                        $capColumn = $columnExists('Tb_IntEventLog', 'capability') ? 'capability' : 'event_capability';
                        $appendCapabilityFilter($where, $params, $capColumn, $capabilityFilter);
                    }

                    $eventSql = "SELECT TOP {$limit}
                                    CONVERT(VARCHAR(19), {$timeExpr}, 120) AS time,
                                    {$deviceIdExpr} AS device_id,
                                    {$deviceIdExpr} AS device_external_id,
                                    {$deviceLabelExpr} AS device_label,
                                    {$capabilityExpr} AS capability,
                                    {$attributeExpr} AS attribute,
                                    {$valueExpr} AS event_value,
                                    {$messageExpr} AS message,
                                    {$providerExpr} AS provider
                                 FROM Tb_IntEventLog
                                 WHERE " . ($where === [] ? '1=1' : implode(' AND ', $where)) . "
                                 ORDER BY {$timeExpr} DESC, idx DESC";
                    $rows = $tryFetchRows($eventSql, $params);
                    foreach ($rows as $row) {
                        $eventValue = trim((string)($row['event_value'] ?? ''));
                        $message = trim((string)($row['message'] ?? ''));
                        if ($message === '') {
                            $message = $eventValue;
                        }
                        $eventItems[] = [
                            'time' => (string)($row['time'] ?? ''),
                            'provider' => strtolower(trim((string)($row['provider'] ?? ''))),
                            'device_id' => (string)($row['device_id'] ?? ''),
                            'device_external_id' => (string)($row['device_external_id'] ?? ''),
                            'device_label' => (string)($row['device_label'] ?? ''),
                            'device_name' => (string)($row['device_label'] ?? ''),
                            'capability' => (string)($row['capability'] ?? ''),
                            'event_capability' => (string)($row['capability'] ?? ''),
                            'value' => $eventValue,
                            'event_value' => $eventValue,
                            'message' => $message,
                            'log_type' => 'event',
                        ];
                    }
                }

                if ($eventItems === []) {
                    $v1Where = ['service_code = ?', 'tenant_id = ?'];
                    $v1Params = [$serviceCode, $tenantId];
                    if ($platform !== '' && $platform !== 'all') {
                        $v1Where[] = 'LOWER(provider) = ?';
                        $v1Params[] = $platform;
                    }
                    $v1Where[] = "UPPER(ISNULL(lifecycle,''))='EVENT'";
                    if ($capabilityFilter !== '') {
                        $appendCapabilityFilter($v1Where, $v1Params, 'capability', $capabilityFilter);
                    }
                    $v1Sql = "SELECT TOP {$limit}
                                CONVERT(VARCHAR(19), ISNULL(event_timestamp, created_at), 120) AS time,
                                device_external_id,
                                capability,
                                attribute,
                                event_value,
                                provider,
                                event_type
                              FROM teniq_db.dbo.Tb_IotEventLog
                              WHERE " . implode(' AND ', $v1Where) . "
                              ORDER BY ISNULL(event_timestamp, created_at) DESC, idx DESC";
                    $rows = $tryFetchRows($v1Sql, $v1Params);
                    foreach ($rows as $row) {
                        $eventValue = trim((string)($row['event_value'] ?? ''));
                        $eventItems[] = [
                            'time' => (string)($row['time'] ?? ''),
                            'provider' => strtolower(trim((string)($row['provider'] ?? ''))),
                            'device_id' => (string)($row['device_external_id'] ?? ''),
                            'device_external_id' => (string)($row['device_external_id'] ?? ''),
                            'device_label' => '',
                            'device_name' => '',
                            'capability' => (string)($row['capability'] ?? ''),
                            'event_capability' => (string)($row['capability'] ?? ''),
                            'value' => $eventValue,
                            'event_value' => $eventValue,
                            'message' => $eventValue,
                            'log_type' => 'event',
                        ];
                    }
                }

                if ($eventItems === []) {
                    $eventProviders = $providers;
                    if ($platform !== '' && $platform !== 'all') {
                        $eventProviders = [$platform];
                    }
                    $cpResult = $service->checkpointList($serviceCode, $tenantId, $eventProviders, '', 1, $limit);
                    foreach (($cpResult['items'] ?? []) as $cp) {
                        $scope = (string)($cp['sync_scope'] ?? $cp['sync_type'] ?? '');
                        $message = (string)($cp['status'] ?? '');
                        if ($scope !== '') {
                            $message .= ($message !== '' ? ': ' : '') . $scope;
                        }
                        if (trim((string)($cp['last_error'] ?? '')) !== '') {
                            $message .= ' - ' . (string)$cp['last_error'];
                        }
                        $eventItems[] = [
                            'time' => (string)($cp['updated_at'] ?? ''),
                            'provider' => strtolower(trim((string)($cp['provider'] ?? ''))),
                            'device_id' => '',
                            'device_external_id' => '',
                            'device_label' => (string)($cp['provider'] ?? ''),
                            'device_name' => (string)($cp['provider'] ?? ''),
                            'capability' => '',
                            'event_capability' => '',
                            'value' => (string)($cp['status'] ?? ''),
                            'event_value' => (string)($cp['status'] ?? ''),
                            'message' => $message,
                            'log_type' => 'event',
                        ];
                    }
                }

                $items = array_merge($items, $eventItems);
            }

            if ($logType !== 'event' && $tableExists('Tb_IntCommandLog')) {
                $cmdTimeExpr = $columnExists('Tb_IntCommandLog', 'created_at')
                    ? 'created_at'
                    : ($columnExists('Tb_IntCommandLog', 'executed_at')
                        ? 'executed_at'
                        : ($columnExists('Tb_IntCommandLog', 'updated_at') ? 'updated_at' : 'GETDATE()'));
                $cmdDeviceIdExpr = $columnExists('Tb_IntCommandLog', 'device_id')
                    ? 'device_id'
                    : ($columnExists('Tb_IntCommandLog', 'device_external_id')
                        ? 'device_external_id'
                        : "CAST('' AS NVARCHAR(120))");
                $cmdDeviceLabelExpr = $columnExists('Tb_IntCommandLog', 'device_label')
                    ? 'device_label'
                    : ($columnExists('Tb_IntCommandLog', 'device_name')
                        ? 'device_name'
                        : "CAST('' AS NVARCHAR(200))");
                $cmdActionExpr = $columnExists('Tb_IntCommandLog', 'command')
                    ? 'command'
                    : ($columnExists('Tb_IntCommandLog', 'action')
                        ? 'action'
                        : "CAST('' AS NVARCHAR(80))");
                $cmdCapExpr = $columnExists('Tb_IntCommandLog', 'capability')
                    ? 'capability'
                    : "CAST('' AS NVARCHAR(120))";
                $cmdResultExpr = $columnExists('Tb_IntCommandLog', 'result')
                    ? 'result'
                    : ($columnExists('Tb_IntCommandLog', 'status')
                        ? 'status'
                        : "CAST('' AS NVARCHAR(40))");
                $cmdErrExpr = $columnExists('Tb_IntCommandLog', 'error_message')
                    ? 'error_message'
                    : ($columnExists('Tb_IntCommandLog', 'error')
                        ? 'error'
                        : "CAST('' AS NVARCHAR(1000))");
                $cmdProviderExpr = $columnExists('Tb_IntCommandLog', 'provider')
                    ? 'provider'
                    : "CAST('' AS NVARCHAR(30))";

                $cmdWhere = [];
                $cmdParams = [];
                if ($columnExists('Tb_IntCommandLog', 'service_code')) {
                    $cmdWhere[] = 'service_code = ?';
                    $cmdParams[] = $serviceCode;
                }
                if ($columnExists('Tb_IntCommandLog', 'tenant_id')) {
                    $cmdWhere[] = 'tenant_id = ?';
                    $cmdParams[] = $tenantId;
                }
                if ($columnExists('Tb_IntCommandLog', 'is_deleted')) {
                    $cmdWhere[] = 'ISNULL(is_deleted,0)=0';
                }
                if ($platform !== '' && $platform !== 'all' && $columnExists('Tb_IntCommandLog', 'provider')) {
                    $cmdWhere[] = 'LOWER(provider)=?';
                    $cmdParams[] = $platform;
                }
                if ($capabilityFilter !== '' && $columnExists('Tb_IntCommandLog', 'capability')) {
                    $appendCapabilityFilter($cmdWhere, $cmdParams, 'capability', $capabilityFilter);
                }

                $cmdSql = "SELECT TOP {$limit}
                            CONVERT(VARCHAR(19), {$cmdTimeExpr}, 120) AS time,
                            {$cmdDeviceIdExpr} AS device_id,
                            {$cmdDeviceIdExpr} AS device_external_id,
                            {$cmdDeviceLabelExpr} AS device_label,
                            {$cmdActionExpr} AS command_value,
                            {$cmdCapExpr} AS capability,
                            {$cmdResultExpr} AS result_state,
                            {$cmdErrExpr} AS error_message,
                            {$cmdProviderExpr} AS provider
                           FROM Tb_IntCommandLog
                           WHERE " . ($cmdWhere === [] ? '1=1' : implode(' AND ', $cmdWhere)) . "
                           ORDER BY {$cmdTimeExpr} DESC, idx DESC";
                $cmdRows = $tryFetchRows($cmdSql, $cmdParams);
                foreach ($cmdRows as $row) {
                    $resultState = trim((string)($row['result_state'] ?? ''));
                    $errorMessage = trim((string)($row['error_message'] ?? ''));
                    $message = trim(($resultState !== '' ? $resultState : 'sent') . ($errorMessage !== '' ? (' - ' . $errorMessage) : ''));
                    $commandValue = trim((string)($row['command_value'] ?? ''));
                    $items[] = [
                        'time' => (string)($row['time'] ?? ''),
                        'provider' => strtolower(trim((string)($row['provider'] ?? ''))),
                        'device_id' => (string)($row['device_id'] ?? ''),
                        'device_external_id' => (string)($row['device_external_id'] ?? ''),
                        'device_label' => (string)($row['device_label'] ?? ''),
                        'device_name' => (string)($row['device_label'] ?? ''),
                        'capability' => (string)($row['capability'] ?? ''),
                        'event_capability' => (string)($row['capability'] ?? ''),
                        'value' => $commandValue,
                        'event_value' => $commandValue,
                        'message' => $message,
                        'log_type' => 'command',
                    ];
                }
            }

            if ($platform !== '' && $platform !== 'all') {
                $items = array_values(array_filter($items, static function (array $row) use ($platform): bool {
                    $provider = strtolower(trim((string)($row['provider'] ?? '')));
                    return $provider === '' || $provider === $platform;
                }));
            }
            if ($capabilityFilter !== '') {
                $items = array_values(array_filter($items, static function (array $row) use ($capabilityFilter): bool {
                    $cap = strtolower(trim((string)($row['capability'] ?? $row['event_capability'] ?? '')));
                    if ($cap === '') {
                        return false;
                    }
                    if (str_contains($capabilityFilter, '.') || str_contains($capabilityFilter, ':')) {
                        return $cap === $capabilityFilter;
                    }
                    return $cap === $capabilityFilter
                        || str_ends_with($cap, '.' . $capabilityFilter)
                        || str_ends_with($cap, ':' . $capabilityFilter);
                }));
            }
            if ($logType !== '') {
                $items = array_values(array_filter($items, static function (array $row) use ($logType): bool {
                    return strtolower(trim((string)($row['log_type'] ?? 'event'))) === $logType;
                }));
            }

            usort($items, static function (array $a, array $b): int {
                $ta = strtotime((string)($a['time'] ?? '')) ?: 0;
                $tb = strtotime((string)($b['time'] ?? '')) ?: 0;
                if ($ta === $tb) {
                    return strcmp((string)($b['message'] ?? ''), (string)($a['message'] ?? ''));
                }
                return $tb <=> $ta;
            });
            if (count($items) > $limit) {
                $items = array_slice($items, 0, $limit);
            }

            ApiResponse::success(['items' => array_values($items), 'count' => count($items)]);
        } catch (Throwable $e) {
            ApiResponse::success([
                'items' => [],
                'count' => 0,
                '_debug' => shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : null,
            ]);
        }
        exit;
    }

    /* ── doorlock_users (GET) ── */
    if ($todo === 'doorlock_users') {
        $deviceId = trim((string)($_GET['device_id'] ?? ''));
        $items = [];
        /* V2 도어락 사용자 테이블이 구축되면 여기서 조회 — 현재는 빈 배열 */
        ApiResponse::success(['items' => $items, 'count' => 0, 'device_id' => $deviceId]);
        exit;
    }

    /* ── doorlock_temp_passwords (GET) ── */
    if ($todo === 'doorlock_temp_passwords') {
        try {
            $deviceId = trim((string)($_GET['device_id'] ?? ''));
            $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
            if ($deviceId === '') {
                ApiResponse::success(['items' => [], 'count' => 0, 'device_id' => '']);
                exit;
            }

            $items = [];
            if ($tableExists('Tb_IntDoorlockTempPassword')) {
                $idExpr = $columnExists('Tb_IntDoorlockTempPassword', 'password_id')
                    ? 'password_id'
                    : ($columnExists('Tb_IntDoorlockTempPassword', 'idx') ? 'CAST(idx AS NVARCHAR(120))' : "CAST('' AS NVARCHAR(120))");
                $nameExpr = $columnExists('Tb_IntDoorlockTempPassword', 'name')
                    ? 'name'
                    : ($columnExists('Tb_IntDoorlockTempPassword', 'password_name') ? 'password_name' : "CAST('' AS NVARCHAR(200))");
                $typeExpr = $columnExists('Tb_IntDoorlockTempPassword', 'type')
                    ? 'type'
                    : ($columnExists('Tb_IntDoorlockTempPassword', 'password_type') ? 'password_type' : "CAST('temp' AS NVARCHAR(40))");
                $effectiveExpr = $columnExists('Tb_IntDoorlockTempPassword', 'effective_time')
                    ? 'effective_time'
                    : ($columnExists('Tb_IntDoorlockTempPassword', 'start_time') ? 'start_time' : 'NULL');
                $invalidExpr = $columnExists('Tb_IntDoorlockTempPassword', 'invalid_time')
                    ? 'invalid_time'
                    : ($columnExists('Tb_IntDoorlockTempPassword', 'end_time') ? 'end_time' : 'NULL');
                $statusExpr = $columnExists('Tb_IntDoorlockTempPassword', 'status')
                    ? 'status'
                    : "CAST('active' AS NVARCHAR(20))";
                $deviceExpr = $columnExists('Tb_IntDoorlockTempPassword', 'device_id')
                    ? 'device_id'
                    : ($columnExists('Tb_IntDoorlockTempPassword', 'device_external_id') ? 'device_external_id' : "CAST('' AS NVARCHAR(120))");
                $orderExpr = $columnExists('Tb_IntDoorlockTempPassword', 'effective_time')
                    ? 'effective_time DESC'
                    : ($columnExists('Tb_IntDoorlockTempPassword', 'created_at') ? 'created_at DESC' : 'idx DESC');

                $where = [];
                $params = [];
                if ($columnExists('Tb_IntDoorlockTempPassword', 'service_code')) {
                    $where[] = 'service_code = ?';
                    $params[] = $serviceCode;
                }
                if ($columnExists('Tb_IntDoorlockTempPassword', 'tenant_id')) {
                    $where[] = 'tenant_id = ?';
                    $params[] = $tenantId;
                }
                if ($columnExists('Tb_IntDoorlockTempPassword', 'is_deleted')) {
                    $where[] = 'ISNULL(is_deleted,0)=0';
                }
                $where[] = "{$deviceExpr} = ?";
                $params[] = $deviceId;

                $sql = "SELECT TOP {$limit}
                            {$idExpr} AS password_id,
                            {$nameExpr} AS name,
                            {$typeExpr} AS type,
                            CONVERT(VARCHAR(19), {$effectiveExpr}, 120) AS effective_time,
                            CONVERT(VARCHAR(19), {$invalidExpr}, 120) AS invalid_time,
                            {$statusExpr} AS status
                        FROM Tb_IntDoorlockTempPassword
                        WHERE " . implode(' AND ', $where) . "
                        ORDER BY {$orderExpr}";
                $items = $tryFetchRows($sql, $params);
            }

            if ($items === []) {
                $v1Tables = [
                    'teniq_db.dbo.Tb_IotDoorLockTempPassword',
                    'teniq_db.dbo.Tb_IotLockTempPassword',
                    'teniq_db.dbo.Tb_IotTempPassword',
                ];
                foreach ($v1Tables as $v1Table) {
                    $rows = $tryFetchRows(
                        "SELECT TOP {$limit} *
                         FROM {$v1Table}
                         WHERE service_code = ? AND tenant_id = ? AND (ISNULL(device_external_id,'') = ? OR ISNULL(device_id,'') = ?)
                         ORDER BY idx DESC",
                        [$serviceCode, $tenantId, $deviceId, $deviceId]
                    );
                    if ($rows === []) {
                        $rows = $tryFetchRows(
                            "SELECT TOP {$limit} *
                             FROM {$v1Table}
                             WHERE ISNULL(device_external_id,'') = ? OR ISNULL(device_id,'') = ?
                             ORDER BY idx DESC",
                            [$deviceId, $deviceId]
                        );
                    }
                    if ($rows === []) {
                        continue;
                    }
                    foreach ($rows as $row) {
                        $items[] = [
                            'password_id' => (string)($row['password_id'] ?? $row['id'] ?? $row['idx'] ?? ''),
                            'name' => (string)($row['name'] ?? $row['password_name'] ?? ''),
                            'type' => (string)($row['type'] ?? $row['password_type'] ?? 'temp'),
                            'effective_time' => (string)($row['effective_time'] ?? $row['start_time'] ?? $row['created_at'] ?? ''),
                            'invalid_time' => (string)($row['invalid_time'] ?? $row['end_time'] ?? ''),
                            'status' => (string)($row['status'] ?? 'active'),
                        ];
                    }
                    break;
                }
            }

            ApiResponse::success([
                'items' => array_values($items),
                'count' => count($items),
                'device_id' => $deviceId,
            ]);
        } catch (Throwable $e) {
            ApiResponse::success([
                'items' => [],
                'count' => 0,
                'device_id' => (string)($_GET['device_id'] ?? ''),
                '_debug' => shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : null,
            ]);
        }
        exit;
    }

    /* ── doorlock_access_mapping (GET) ── */
    if ($todo === 'doorlock_access_mapping') {
        try {
            $deviceId = trim((string)($_GET['device_id'] ?? ''));
            $limit = max(1, min(300, (int)($_GET['limit'] ?? 200)));
            if ($deviceId === '') {
                ApiResponse::success(['items' => [], 'count' => 0, 'device_id' => '']);
                exit;
            }

            $items = [];
            if ($tableExists('Tb_IntLockCredentialMap')) {
                $idExpr = $columnExists('Tb_IntLockCredentialMap', 'credential_id')
                    ? 'credential_id'
                    : ($columnExists('Tb_IntLockCredentialMap', 'credential_key')
                        ? 'credential_key'
                        : ($columnExists('Tb_IntLockCredentialMap', 'idx') ? 'CAST(idx AS NVARCHAR(120))' : "CAST('' AS NVARCHAR(120))"));
                $typeExpr = $columnExists('Tb_IntLockCredentialMap', 'auth_type')
                    ? 'auth_type'
                    : ($columnExists('Tb_IntLockCredentialMap', 'credential_type') ? 'credential_type' : "CAST('' AS NVARCHAR(60))");
                $labelExpr = $columnExists('Tb_IntLockCredentialMap', 'label')
                    ? 'label'
                    : ($columnExists('Tb_IntLockCredentialMap', 'credential_label') ? 'credential_label' : "CAST('' AS NVARCHAR(200))");
                $employeeExpr = $columnExists('Tb_IntLockCredentialMap', 'employee_name')
                    ? 'employee_name'
                    : "CAST('' AS NVARCHAR(200))";
                $noteExpr = $columnExists('Tb_IntLockCredentialMap', 'notes')
                    ? 'notes'
                    : ($columnExists('Tb_IntLockCredentialMap', 'note') ? 'note' : "CAST('' AS NVARCHAR(500))");
                $deviceExpr = $columnExists('Tb_IntLockCredentialMap', 'device_id')
                    ? 'device_id'
                    : ($columnExists('Tb_IntLockCredentialMap', 'device_external_id') ? 'device_external_id' : "CAST('' AS NVARCHAR(120))");
                $orderExpr = $columnExists('Tb_IntLockCredentialMap', 'updated_at')
                    ? 'updated_at DESC'
                    : 'idx DESC';

                $where = [];
                $params = [];
                if ($columnExists('Tb_IntLockCredentialMap', 'service_code')) {
                    $where[] = 'service_code = ?';
                    $params[] = $serviceCode;
                }
                if ($columnExists('Tb_IntLockCredentialMap', 'tenant_id')) {
                    $where[] = 'tenant_id = ?';
                    $params[] = $tenantId;
                }
                if ($columnExists('Tb_IntLockCredentialMap', 'is_deleted')) {
                    $where[] = 'ISNULL(is_deleted,0)=0';
                }
                if ($columnExists('Tb_IntLockCredentialMap', 'is_active')) {
                    $where[] = 'ISNULL(is_active,1)=1';
                }
                $where[] = "{$deviceExpr} = ?";
                $params[] = $deviceId;

                $sql = "SELECT TOP {$limit}
                            {$idExpr} AS credential_id,
                            {$typeExpr} AS auth_type,
                            {$labelExpr} AS label,
                            {$employeeExpr} AS employee_name,
                            {$noteExpr} AS notes
                        FROM Tb_IntLockCredentialMap
                        WHERE " . implode(' AND ', $where) . "
                        ORDER BY {$orderExpr}";
                $items = $tryFetchRows($sql, $params);
            }

            if ($items === []) {
                $rows = $tryFetchRows(
                    "SELECT TOP {$limit}
                        ISNULL(credential_key, CAST(idx AS NVARCHAR(120))) AS credential_id,
                        credential_type AS auth_type,
                        credential_label AS label,
                        employee_name,
                        note AS notes
                     FROM teniq_db.dbo.Tb_IotLockCredentialMap
                     WHERE service_code = ? AND tenant_id = ?
                       AND ISNULL(is_deleted,0) = 0
                       AND ISNULL(is_active,1) = 1
                       AND ISNULL(device_external_id,'') = ?
                     ORDER BY updated_at DESC, idx DESC",
                    [$serviceCode, $tenantId, $deviceId]
                );
                if ($rows === []) {
                    $rows = $tryFetchRows(
                        "SELECT TOP {$limit}
                            ISNULL(credential_key, CAST(idx AS NVARCHAR(120))) AS credential_id,
                            credential_type AS auth_type,
                            credential_label AS label,
                            employee_name,
                            note AS notes
                         FROM teniq_db.dbo.Tb_IotLockCredentialMap
                         WHERE ISNULL(is_deleted,0) = 0
                           AND ISNULL(is_active,1) = 1
                           AND ISNULL(device_external_id,'') = ?
                         ORDER BY updated_at DESC, idx DESC",
                        [$deviceId]
                    );
                }
                $items = $rows;
            }

            ApiResponse::success([
                'items' => array_values($items),
                'count' => count($items),
                'device_id' => $deviceId,
            ]);
        } catch (Throwable $e) {
            ApiResponse::success([
                'items' => [],
                'count' => 0,
                'device_id' => (string)($_GET['device_id'] ?? ''),
                '_debug' => shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : null,
            ]);
        }
        exit;
    }

    /* ── doorlock_log (GET) ── */
    if ($todo === 'doorlock_log') {
        $deviceId = trim((string)($_GET['device_id'] ?? ''));
        $days     = (int)($_GET['days'] ?? 0);
        $items    = [];
        /* V2 도어락 출입 로그 테이블이 구축되면 여기서 조회 — 현재는 빈 배열 */
        ApiResponse::success(['items' => $items, 'count' => 0, 'device_id' => $deviceId]);
        exit;
    }

    /* ── settings_form (GET) — 모달에 렌더할 HTML 반환 ── */
    if ($todo === 'settings_form') {
        if ($roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '관리자 권한이 필요합니다', 403);
            exit;
        }
        /* 현재 연결된 계정 정보 조회 */
        $accounts = [];
        $stSummary = [
            'installed_app_id' => '',
            'subscription_count' => 0,
            'missing_count' => 0,
            'orphan_count' => 0,
        ];
        $tuyaSettings = [
            'region' => '',
            'access_id' => '',
            'access_secret_hint' => '',
            'app_uid' => '',
            'account_idx' => 0,
        ];
        if ($tableExists('Tb_IntProviderAccount')) {
            $labelExpr = $columnExists('Tb_IntProviderAccount', 'account_label')
                ? 'account_label'
                : ($columnExists('Tb_IntProviderAccount', 'display_name')
                    ? 'display_name'
                        : ($columnExists('Tb_IntProviderAccount', 'account_email')
                            ? 'account_email'
                            : "CAST('' AS NVARCHAR(200))"));
            $createdExpr = $columnExists('Tb_IntProviderAccount', 'created_at')
                ? 'created_at'
                : ($columnExists('Tb_IntProviderAccount', 'updated_at') ? 'updated_at' : 'GETDATE()');
            $rawExpr = $columnExists('Tb_IntProviderAccount', 'raw_json')
                ? 'raw_json'
                : "CAST('' AS NVARCHAR(MAX))";
            $where = ['service_code=?', 'tenant_id=?'];
            $params = [$serviceCode, $tenantId];
            if ($columnExists('Tb_IntProviderAccount', 'is_deleted')) {
                $where[] = 'ISNULL(is_deleted,0)=0';
            }
            $st = $db->prepare("SELECT idx, provider, {$labelExpr} AS account_label, status, {$rawExpr} AS raw_json,
                                       CONVERT(VARCHAR(19), {$createdExpr}, 120) AS created_at
                                FROM Tb_IntProviderAccount
                                WHERE " . implode(' AND ', $where) . "
                                ORDER BY provider, {$createdExpr} DESC");
            $st->execute($params);
            $accounts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($accounts as $acc) {
                $provider = strtolower(trim((string)($acc['provider'] ?? '')));
                $rawJson = (string)($acc['raw_json'] ?? '');
                $raw = json_decode($rawJson, true);
                if (!is_array($raw)) {
                    $raw = [];
                }
                $summary = is_array($raw['summary'] ?? null) ? $raw['summary'] : [];

                if (($provider === 'smartthings' || $provider === 'st') && $stSummary['installed_app_id'] === '') {
                    $stSummary['installed_app_id'] = trim((string)(
                        $raw['installed_app_id']
                        ?? $raw['installedAppId']
                        ?? $summary['installed_app_id']
                        ?? ''
                    ));
                    $stSummary['subscription_count'] = (int)(
                        $summary['subscription_count']
                        ?? $summary['subscription_device_count']
                        ?? $raw['subscription_count']
                        ?? 0
                    );
                    $stSummary['missing_count'] = (int)(
                        $summary['missing_count']
                        ?? $summary['missing_device_count']
                        ?? $raw['missing_count']
                        ?? 0
                    );
                    $stSummary['orphan_count'] = (int)(
                        $summary['orphan_count']
                        ?? $summary['orphan_subscription_count']
                        ?? $raw['orphan_count']
                        ?? 0
                    );
                }

                if (str_contains($provider, 'tuya') && $tuyaSettings['account_idx'] === 0) {
                    $tuyaSettings['account_idx'] = (int)($acc['idx'] ?? 0);
                    $tuyaSettings['region'] = trim((string)(
                        $raw['region']
                        ?? $raw['endpoint_region']
                        ?? $raw['country_code']
                        ?? ''
                    ));
                    $tuyaSettings['access_id'] = trim((string)(
                        $raw['access_id']
                        ?? $raw['client_id']
                        ?? $raw['accessKey']
                        ?? ''
                    ));
                    $tuyaSettings['app_uid'] = trim((string)(
                        $raw['app_uid']
                        ?? $raw['uid']
                        ?? $raw['user_uid']
                        ?? ''
                    ));
                    $rawSecret = trim((string)($raw['access_secret'] ?? $raw['client_secret'] ?? ''));
                    if ($rawSecret !== '') {
                        $tuyaSettings['access_secret_hint'] = str_repeat('*', 8);
                    }
                }
            }
        }

        if ($stSummary['installed_app_id'] === '') {
            $hookRows = $tryFetchRows(
                "SELECT TOP 1 *
                 FROM teniq_db.dbo.Tb_IotWebhookApp
                 WHERE service_code = ? AND tenant_id = ?
                 ORDER BY idx DESC",
                [$serviceCode, $tenantId]
            );
            if ($hookRows !== []) {
                $hook = $hookRows[0];
                $stSummary['installed_app_id'] = trim((string)($hook['installed_app_id'] ?? ''));
                $stSummary['subscription_count'] = (int)($hook['subscription_count'] ?? $stSummary['subscription_count']);
                $stSummary['missing_count'] = (int)($hook['missing_count'] ?? $stSummary['missing_count']);
                $stSummary['orphan_count'] = (int)($hook['orphan_count'] ?? $stSummary['orphan_count']);
            }
        }

        if ($tuyaSettings['access_secret_hint'] === '' && $tuyaSettings['account_idx'] > 0 && $tableExists('Tb_IntCredential')) {
            $credRows = $tryFetchRows(
                "SELECT TOP 20 secret_type, secret_value_enc
                 FROM Tb_IntCredential
                 WHERE provider_account_idx = ?
                 ORDER BY idx DESC",
                [$tuyaSettings['account_idx']]
            );
            foreach ($credRows as $credRow) {
                $secretType = strtolower(trim((string)($credRow['secret_type'] ?? '')));
                $secretVal = trim($decryptSecretValue((string)($credRow['secret_value_enc'] ?? '')));
                if ($secretVal === '') {
                    continue;
                }
                if ($tuyaSettings['access_id'] === '' && in_array($secretType, ['access_id', 'client_id', 'app_key'], true)) {
                    $tuyaSettings['access_id'] = $secretVal;
                    continue;
                }
                if ($tuyaSettings['access_secret_hint'] === '' && in_array($secretType, ['access_secret', 'client_secret', 'secret_key'], true)) {
                    $len = strlen($secretVal);
                    if ($len <= 4) {
                        $tuyaSettings['access_secret_hint'] = str_repeat('*', max(4, $len));
                    } else {
                        $tuyaSettings['access_secret_hint'] = substr($secretVal, 0, 2)
                            . str_repeat('*', max(4, $len - 4))
                            . substr($secretVal, -2);
                    }
                }
            }
        }
        /* HTML 반환 (SHV.modal.open이 body에 주입) */
        header('Content-Type: text/html; charset=utf-8');
        echo '<div class="p-4">';
        echo '<h3 class="m-0 text-md font-bold text-1 mb-3">IoT 플랫폼 계정</h3>';
        if (empty($accounts)) {
            echo '<div class="text-sm text-3 text-center p-4">연결된 계정이 없습니다. SmartThings 또는 Tuya 계정을 연결하세요.</div>';
        } else {
            echo '<table class="tbl"><thead><tr><th>플랫폼</th><th>계정</th><th>상태</th><th>연결일</th></tr></thead><tbody>';
            foreach ($accounts as $a) {
                $statusText = strtoupper((string)($a['status'] ?? ''));
                $badge = $statusText === 'ACTIVE'
                    ? '<span class="ov-badge ov-badge-green">활성</span>'
                    : '<span class="ov-badge ov-badge-gray">' . htmlspecialchars((string)($a['status'] ?? ''), ENT_QUOTES, 'UTF-8') . '</span>';
                echo '<tr>'
                    . '<td>' . htmlspecialchars((string)($a['provider'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td>' . htmlspecialchars((string)($a['account_label'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td>' . $badge . '</td>'
                    . '<td class="text-xs text-3">' . htmlspecialchars((string)($a['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>'
                    . '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '<div class="mt-4 p-3" style="border:1px solid var(--border,#e6ecf2);border-radius:10px;background:#f9fbff;">';
        echo '<h4 class="m-0 text-sm font-bold text-1 mb-2">SmartThings 구독 상태</h4>';
        if ($stSummary['installed_app_id'] === '') {
            echo '<div class="text-xs text-3">설치 앱 ID가 없습니다. OAuth 연결 및 구독 동기화가 필요합니다.</div>';
        } else {
            echo '<div class="text-xs text-2 mb-2"><span class="ov-badge ov-badge-blue">installed_app_id</span> '
                . htmlspecialchars($stSummary['installed_app_id'], ENT_QUOTES, 'UTF-8') . '</div>';
            echo '<div class="flex gap-2 flex-wrap text-xs">';
            echo '<span class="ov-badge ov-badge-green">subscription_count ' . (int)$stSummary['subscription_count'] . '</span>';
            echo '<span class="ov-badge ov-badge-yellow">missing_count ' . (int)$stSummary['missing_count'] . '</span>';
            echo '<span class="ov-badge ov-badge-gray">orphan_count ' . (int)$stSummary['orphan_count'] . '</span>';
            echo '</div>';
        }
        echo '<div class="mt-2"><button type="button" class="btn btn-outline btn-sm" disabled>SmartThings PAT 연결 테스트 (준비중)</button></div>';
        echo '</div>';

        echo '<div class="mt-4 p-3" style="border:1px solid var(--border,#e6ecf2);border-radius:10px;background:#fffaf5;">';
        echo '<h4 class="m-0 text-sm font-bold text-1 mb-2">Tuya API 설정</h4>';
        echo '<div class="grid" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">';
        echo '<label class="text-xs text-3">Region'
            . '<input class="form-input mt-1" type="text" value="' . htmlspecialchars((string)$tuyaSettings['region'], ENT_QUOTES, 'UTF-8') . '" placeholder="kr / us / eu / cn" readonly>'
            . '</label>';
        echo '<label class="text-xs text-3">Access ID'
            . '<input class="form-input mt-1" type="text" value="' . htmlspecialchars((string)$tuyaSettings['access_id'], ENT_QUOTES, 'UTF-8') . '" placeholder="access_id" readonly>'
            . '</label>';
        echo '<label class="text-xs text-3">Access Secret'
            . '<input class="form-input mt-1" type="text" value="' . htmlspecialchars((string)$tuyaSettings['access_secret_hint'], ENT_QUOTES, 'UTF-8') . '" placeholder="********" readonly>'
            . '</label>';
        echo '<label class="text-xs text-3">App UID'
            . '<input class="form-input mt-1" type="text" value="' . htmlspecialchars((string)$tuyaSettings['app_uid'], ENT_QUOTES, 'UTF-8') . '" placeholder="app_uid" readonly>'
            . '</label>';
        echo '</div>';
        echo '<div class="mt-2"><button type="button" class="btn btn-outline btn-sm" disabled>Tuya API 연결 테스트 (준비중)</button></div>';
        echo '</div>';

        echo '<div class="mt-4 text-xs text-3">설정 저장/연결 테스트 액션은 Wave 12에서 API 연동 예정입니다.</div>';
        echo '</div>';
        exit;
    }

    /* ── device_sort_order (POST + CSRF + role_level<=2) ── */
    if ($todo === 'device_sort_order') {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            ApiResponse::error('METHOD_NOT_ALLOWED', 'POST method required', 405);
            exit;
        }
        if (!$auth->validateCsrf()) {
            ApiResponse::error('CSRF_TOKEN_INVALID', '보안 토큰이 유효하지 않습니다', 403);
            exit;
        }
        if ($roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '관리자 권한이 필요합니다', 403);
            exit;
        }
        if (!$tableExists('Tb_IntDevice')) {
            ApiResponse::error('TABLE_NOT_FOUND', 'Tb_IntDevice 테이블이 없습니다', 404);
            exit;
        }
        if (!$columnExists('Tb_IntDevice', 'sort_order')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_IntDevice.sort_order 컬럼이 없습니다', 503);
            exit;
        }

        $roomId = trim((string)($_POST['room_id'] ?? ''));
        $rawOrders = $_POST['device_orders'] ?? [];
        if (is_string($rawOrders)) {
            $decoded = json_decode($rawOrders, true);
            $rawOrders = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($rawOrders)) {
            ApiResponse::error('INVALID_INPUT', 'device_orders 형식이 올바르지 않습니다', 422);
            exit;
        }

        $orders = [];
        foreach ($rawOrders as $row) {
            if (!is_array($row)) {
                continue;
            }
            $idx = (int)($row['device_idx'] ?? 0);
            if ($idx <= 0) {
                continue;
            }
            $sortOrder = (int)($row['sort_order'] ?? 9999);
            $orders[$idx] = $sortOrder;
        }
        if ($orders === []) {
            ApiResponse::error('INVALID_INPUT', '저장할 장치 순서가 없습니다', 422);
            exit;
        }

        $roomLocation = '';
        $roomName = '';
        if ($roomId !== '' && str_contains($roomId, ':')) {
            [$roomLocation, $roomName] = array_pad(explode(':', $roomId, 2), 2, '');
            $roomLocation = trim($roomLocation);
            $roomName = trim($roomName);
        }

        $updated = 0;
        $affectedDevices = 0;
        $db->beginTransaction();
        try {
            foreach ($orders as $idx => $sortOrder) {
                $setSql = ['sort_order = ?'];
                $params = [$sortOrder];
                if ($columnExists('Tb_IntDevice', 'updated_at')) {
                    $setSql[] = 'updated_at = GETDATE()';
                }

                $where = ['idx = ?'];
                $params[] = $idx;
                if ($columnExists('Tb_IntDevice', 'service_code')) {
                    $where[] = 'service_code = ?';
                    $params[] = $serviceCode;
                }
                if ($columnExists('Tb_IntDevice', 'tenant_id')) {
                    $where[] = 'tenant_id = ?';
                    $params[] = $tenantId;
                }
                if ($columnExists('Tb_IntDevice', 'is_deleted')) {
                    $where[] = 'ISNULL(is_deleted,0)=0';
                }
                if ($roomLocation !== '' && $roomName !== '' && $columnExists('Tb_IntDevice', 'location_name') && $columnExists('Tb_IntDevice', 'room_name')) {
                    $where[] = 'ISNULL(location_name, \'\') = ?';
                    $where[] = 'ISNULL(room_name, \'\') = ?';
                    $params[] = $roomLocation;
                    $params[] = $roomName;
                }

                $sql = "UPDATE Tb_IntDevice
                        SET " . implode(', ', $setSql) . "
                        WHERE " . implode(' AND ', $where);
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $cnt = (int)$stmt->rowCount();
                if ($cnt > 0) {
                    $affectedDevices++;
                    $updated += $cnt;
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        ApiResponse::success([
            'room_id' => $roomId,
            'requested' => count($orders),
            'affected_devices' => $affectedDevices,
            'updated_rows' => $updated,
        ], 'OK', '장치 정렬 순서가 저장되었습니다');
        exit;
    }

    /* ── device_visibility (POST + CSRF + role_level<=2) ── */
    if ($todo === 'device_visibility') {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            ApiResponse::error('METHOD_NOT_ALLOWED', 'POST method required', 405);
            exit;
        }
        if (!$auth->validateCsrf()) {
            ApiResponse::error('CSRF_TOKEN_INVALID', '보안 토큰이 유효하지 않습니다', 403);
            exit;
        }
        if ($roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '관리자 권한이 필요합니다', 403);
            exit;
        }
        if (!$tableExists('Tb_IntDevice')) {
            ApiResponse::error('TABLE_NOT_FOUND', 'Tb_IntDevice 테이블이 없습니다', 404);
            exit;
        }
        if (!$columnExists('Tb_IntDevice', 'is_hidden')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_IntDevice.is_hidden 컬럼이 없습니다', 503);
            exit;
        }

        $deviceIdx = (int)($_POST['device_idx'] ?? 0);
        $hiddenRaw = $_POST['hidden'] ?? $_POST['is_hidden'] ?? null;
        if ($deviceIdx <= 0 || !in_array((string)$hiddenRaw, ['0', '1', 0, 1], true)) {
            ApiResponse::error('INVALID_INPUT', 'device_idx, hidden(0/1) 값이 필요합니다', 422);
            exit;
        }
        $hidden = (int)$hiddenRaw;

        $setSql = ['is_hidden = ?'];
        $params = [$hidden];
        if ($columnExists('Tb_IntDevice', 'updated_at')) {
            $setSql[] = 'updated_at = GETDATE()';
        }
        $where = ['idx = ?'];
        $params[] = $deviceIdx;
        if ($columnExists('Tb_IntDevice', 'service_code')) {
            $where[] = 'service_code = ?';
            $params[] = $serviceCode;
        }
        if ($columnExists('Tb_IntDevice', 'tenant_id')) {
            $where[] = 'tenant_id = ?';
            $params[] = $tenantId;
        }
        if ($columnExists('Tb_IntDevice', 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }

        $stmt = $db->prepare("UPDATE Tb_IntDevice
                              SET " . implode(', ', $setSql) . "
                              WHERE " . implode(' AND ', $where));
        $stmt->execute($params);
        if ((int)$stmt->rowCount() <= 0) {
            ApiResponse::error('NOT_FOUND', '변경할 장치를 찾을 수 없습니다', 404, ['device_idx' => $deviceIdx]);
            exit;
        }

        ApiResponse::success([
            'device_idx' => $deviceIdx,
            'is_hidden' => $hidden,
        ], 'OK', $hidden === 1 ? '장치를 숨겼습니다' : '장치를 표시했습니다');
        exit;
    }

    /* ── device_cmd (POST + CSRF + role_level<=2) ── */
    if ($todo === 'device_cmd') {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            ApiResponse::error('METHOD_NOT_ALLOWED', 'POST method required', 405);
            exit;
        }
        if (!$auth->validateCsrf()) {
            ApiResponse::error('CSRF_TOKEN_INVALID', '보안 토큰이 유효하지 않습니다', 403);
            exit;
        }
        if ($roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '관리자 권한이 필요합니다', 403);
            exit;
        }

        $deviceIdx = (int)($_POST['device_idx'] ?? 0);
        $command = strtolower(trim((string)($_POST['command'] ?? '')));
        $allowedCommands = ['on', 'off', 'open', 'close', 'lock', 'unlock'];
        if ($deviceIdx <= 0 || !in_array($command, $allowedCommands, true)) {
            ApiResponse::error('INVALID_INPUT', 'device_idx 또는 command 값이 올바르지 않습니다', 422, [
                'allowed_commands' => $allowedCommands,
            ]);
            exit;
        }
        if (!$tableExists('Tb_IntDevice')) {
            ApiResponse::error('TABLE_NOT_FOUND', 'Tb_IntDevice 테이블이 없습니다', 404);
            exit;
        }

        $idExpr = $columnExists('Tb_IntDevice', 'device_id')
            ? 'device_id'
            : ($columnExists('Tb_IntDevice', 'external_id') ? 'external_id' : "CAST('' AS NVARCHAR(120))");
        $providerExpr = $columnExists('Tb_IntDevice', 'provider')
            ? 'provider'
            : "CAST('' AS NVARCHAR(50))";
        $adapterExpr = $columnExists('Tb_IntDevice', 'adapter')
            ? 'adapter'
            : $providerExpr;
        $providerAccountExpr = $columnExists('Tb_IntDevice', 'provider_account_idx')
            ? 'provider_account_idx'
            : 'CAST(0 AS INT)';
        $typeExpr = $columnExists('Tb_IntDevice', 'device_type')
            ? 'device_type'
            : "CAST('device' AS NVARCHAR(50))";
        $deviceLabelExpr = $columnExists('Tb_IntDevice', 'device_name')
            ? 'device_name'
            : ($columnExists('Tb_IntDevice', 'device_label')
                ? 'device_label'
                : $idExpr);
        $lastStateExpr = $columnExists('Tb_IntDevice', 'last_state')
            ? 'last_state'
            : "CAST('' AS NVARCHAR(100))";
        $capabilityExpr = $columnExists('Tb_IntDevice', 'capability_json')
            ? 'capability_json'
            : ($columnExists('Tb_IntDevice', 'capabilities_json')
                ? 'capabilities_json'
                : ($columnExists('Tb_IntDevice', 'raw_json')
                    ? 'raw_json'
                    : "CAST('' AS NVARCHAR(MAX))"));

        $where = ['idx = ?'];
        $params = [$deviceIdx];
        if ($columnExists('Tb_IntDevice', 'service_code')) {
            $where[] = 'service_code = ?';
            $params[] = $serviceCode;
        }
        if ($columnExists('Tb_IntDevice', 'tenant_id')) {
            $where[] = 'tenant_id = ?';
            $params[] = $tenantId;
        }
        if ($columnExists('Tb_IntDevice', 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }

        $sql = "SELECT TOP 1
                    idx,
                    {$idExpr} AS device_id,
                    {$providerExpr} AS provider,
                    {$adapterExpr} AS adapter,
                    {$providerAccountExpr} AS provider_account_idx,
                    {$typeExpr} AS device_type,
                    {$deviceLabelExpr} AS device_label,
                    {$lastStateExpr} AS last_state,
                    {$capabilityExpr} AS capability_raw
                FROM Tb_IntDevice
                WHERE " . implode(' AND ', $where);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $device = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($device === []) {
            ApiResponse::error('DEVICE_NOT_FOUND', '장치를 찾을 수 없습니다', 404, ['device_idx' => $deviceIdx]);
            exit;
        }

        $bridge = strtolower(trim((string)($device['adapter'] ?? '')));
        if ($bridge === '') {
            $bridge = strtolower(trim((string)($device['provider'] ?? '')));
        }
        if ($bridge === '') {
            $bridge = 'smartthings';
        }
        if ($bridge !== 'smartthings' && $bridge !== 'st' && strpos($bridge, 'smart') === false) {
            ApiResponse::error('UNSUPPORTED_PROVIDER', '현재 SmartThings 장치만 제어할 수 있습니다', 422, [
                'provider' => $bridge,
            ]);
            exit;
        }

        $deviceId = trim((string)($device['device_id'] ?? ''));
        if ($deviceId === '') {
            ApiResponse::error('DEVICE_ID_MISSING', '장치 고유 ID가 없습니다', 422, ['device_idx' => $deviceIdx]);
            exit;
        }

        $meta = $buildControlMeta(
            (string)($device['capability_raw'] ?? ''),
            (string)($device['device_type'] ?? ''),
            (string)($device['last_state'] ?? '')
        );
        $cmds = is_array($meta['cmds'] ?? null) ? array_values(array_map('strval', $meta['cmds'])) : [];
        if ($cmds !== [] && !in_array($command, $cmds, true)) {
            ApiResponse::error('COMMAND_NOT_ALLOWED', '해당 장치에서 지원하지 않는 명령입니다', 422, [
                'device_idx' => $deviceIdx,
                'command' => $command,
                'available' => $cmds,
            ]);
            exit;
        }

        $tokenInfo = $resolveSmartThingsToken((int)($device['provider_account_idx'] ?? 0), $serviceCode, $tenantId);
        $token = trim((string)($tokenInfo['token'] ?? ''));
        if ($token === '') {
            ApiResponse::error('TOKEN_NOT_FOUND', 'SmartThings 토큰을 찾을 수 없습니다', 422, [
                'provider_account_idx' => (int)($tokenInfo['account_idx'] ?? 0),
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'token_source' => (string)($tokenInfo['source'] ?? 'none'),
                'token_debug' => is_array($tokenInfo['debug'] ?? null) ? $tokenInfo['debug'] : [],
            ]);
            exit;
        }

        $commandMap = [
            'on' => [
                ['capability' => 'switch', 'command' => 'on'],
            ],
            'off' => [
                ['capability' => 'switch', 'command' => 'off'],
            ],
            'lock' => [
                ['capability' => 'lock', 'command' => 'lock'],
            ],
            'unlock' => [
                ['capability' => 'lock', 'command' => 'unlock'],
            ],
            'open' => [
                ['capability' => 'doorControl', 'command' => 'open'],
                ['capability' => 'windowShade', 'command' => 'open'],
                ['capability' => 'windowShadeLevel', 'command' => 'open'],
            ],
            'close' => [
                ['capability' => 'doorControl', 'command' => 'close'],
                ['capability' => 'windowShade', 'command' => 'close'],
                ['capability' => 'windowShadeLevel', 'command' => 'close'],
            ],
        ];
        $candidates = $commandMap[$command] ?? [];
        if ($candidates === []) {
            ApiResponse::error('INVALID_COMMAND', '지원하지 않는 명령입니다', 422, ['command' => $command]);
            exit;
        }

        $requestUrl = 'https://api.smartthings.com/v1/devices/' . rawurlencode($deviceId) . '/commands';
        $attempts = [];
        $success = false;
        $response = ['status' => 0, 'json' => [], 'body' => '', 'error' => '', 'ok' => false];
        $executedCapability = '';
        foreach ($candidates as $candidate) {
            $executedCapability = (string)($candidate['capability'] ?? '');
            $body = [
                'commands' => [[
                    'component' => 'main',
                    'capability' => $executedCapability,
                    'command' => (string)($candidate['command'] ?? $command),
                ]],
            ];
            $response = $smartThingsRequest('POST', $requestUrl, $body, $token);
            $attempts[] = [
                'capability' => $executedCapability,
                'status' => (int)($response['status'] ?? 0),
                'ok' => (bool)($response['ok'] ?? false),
            ];
            if ((bool)($response['ok'] ?? false)) {
                $success = true;
                break;
            }
        }

        if (!$success) {
            ApiResponse::error('DEVICE_COMMAND_FAILED', 'SmartThings 제어 요청에 실패했습니다', 502, [
                'device_idx' => $deviceIdx,
                'command' => $command,
                'attempts' => $attempts,
                'http_status' => (int)($response['status'] ?? 0),
                'error' => (string)($response['error'] ?? ''),
            ]);
            exit;
        }

        $setSql = [];
        $setParams = [];
        if ($columnExists('Tb_IntDevice', 'last_state')) {
            $setSql[] = 'last_state = ?';
            $setParams[] = $command;
        }
        if ($columnExists('Tb_IntDevice', 'last_event_at')) {
            $setSql[] = 'last_event_at = GETDATE()';
        }
        if ($columnExists('Tb_IntDevice', 'updated_at')) {
            $setSql[] = 'updated_at = GETDATE()';
        }
        if ($setSql !== []) {
            $updWhere = ['idx = ?'];
            $updParams = array_merge($setParams, [$deviceIdx]);
            if ($columnExists('Tb_IntDevice', 'service_code')) {
                $updWhere[] = 'service_code = ?';
                $updParams[] = $serviceCode;
            }
            if ($columnExists('Tb_IntDevice', 'tenant_id')) {
                $updWhere[] = 'tenant_id = ?';
                $updParams[] = $tenantId;
            }
            if ($columnExists('Tb_IntDevice', 'is_deleted')) {
                $updWhere[] = 'ISNULL(is_deleted,0)=0';
            }
            $upd = $db->prepare("UPDATE Tb_IntDevice
                                 SET " . implode(', ', $setSql) . "
                                 WHERE " . implode(' AND ', $updWhere));
            $upd->execute($updParams);
        }

        if ($tableExists('Tb_IntCommandLog')) {
            $insertCols = [];
            $insertVals = [];
            $insertParams = [];
            $addInsert = static function (string $col, string $val, $param = null) use (&$insertCols, &$insertVals, &$insertParams): void {
                $insertCols[] = $col;
                $insertVals[] = $val;
                if ($val === '?') {
                    $insertParams[] = $param;
                }
            };

            if ($columnExists('Tb_IntCommandLog', 'service_code')) {
                $addInsert('service_code', '?', $serviceCode);
            }
            if ($columnExists('Tb_IntCommandLog', 'tenant_id')) {
                $addInsert('tenant_id', '?', $tenantId);
            }
            if ($columnExists('Tb_IntCommandLog', 'provider')) {
                $addInsert('provider', '?', $bridge);
            }
            if ($columnExists('Tb_IntCommandLog', 'provider_account_idx')) {
                $addInsert('provider_account_idx', '?', (int)($tokenInfo['account_idx'] ?? 0));
            }
            if ($columnExists('Tb_IntCommandLog', 'device_idx')) {
                $addInsert('device_idx', '?', $deviceIdx);
            }
            if ($columnExists('Tb_IntCommandLog', 'device_id')) {
                $addInsert('device_id', '?', $deviceId);
            } elseif ($columnExists('Tb_IntCommandLog', 'device_external_id')) {
                $addInsert('device_external_id', '?', $deviceId);
            }
            if ($columnExists('Tb_IntCommandLog', 'device_label')) {
                $addInsert('device_label', '?', (string)($device['device_label'] ?? ''));
            } elseif ($columnExists('Tb_IntCommandLog', 'device_name')) {
                $addInsert('device_name', '?', (string)($device['device_label'] ?? ''));
            }
            if ($columnExists('Tb_IntCommandLog', 'capability')) {
                $addInsert('capability', '?', $executedCapability);
            }
            if ($columnExists('Tb_IntCommandLog', 'command')) {
                $addInsert('command', '?', $command);
            } elseif ($columnExists('Tb_IntCommandLog', 'action')) {
                $addInsert('action', '?', $command);
            }
            if ($columnExists('Tb_IntCommandLog', 'result')) {
                $addInsert('result', '?', 'success');
            } elseif ($columnExists('Tb_IntCommandLog', 'status')) {
                $addInsert('status', '?', 'success');
            }
            if ($columnExists('Tb_IntCommandLog', 'http_status')) {
                $addInsert('http_status', '?', (int)($response['status'] ?? 0));
            }
            if ($columnExists('Tb_IntCommandLog', 'raw_json')) {
                $addInsert('raw_json', '?', json_encode($response['json'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            if ($columnExists('Tb_IntCommandLog', 'created_at')) {
                $addInsert('created_at', 'GETDATE()');
            }
            if ($columnExists('Tb_IntCommandLog', 'updated_at')) {
                $addInsert('updated_at', 'GETDATE()');
            }

            if ($insertCols !== []) {
                $sql = "INSERT INTO Tb_IntCommandLog (" . implode(', ', $insertCols) . ")
                        VALUES (" . implode(', ', $insertVals) . ")";
                try {
                    $ins = $db->prepare($sql);
                    $ins->execute($insertParams);
                } catch (Throwable) {
                    /* command log insert failure should not break device control response */
                }
            }
        }

        ApiResponse::success([
            'device_idx' => $deviceIdx,
            'device_id' => $deviceId,
            'provider' => $bridge,
            'command' => $command,
            'capability' => $executedCapability,
            'http_status' => (int)($response['status'] ?? 0),
            'provider_account_idx' => (int)($tokenInfo['account_idx'] ?? 0),
            'token_source' => (string)($tokenInfo['source'] ?? 'unknown'),
            'attempts' => $attempts,
        ], 'OK', '장치 제어 요청이 전송되었습니다');
        exit;
    }

    /* ── sync_devices (POST + CSRF + role_level<=2) ── */
    if ($todo === 'sync_devices') {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            ApiResponse::error('METHOD_NOT_ALLOWED', 'POST method required', 405);
            exit;
        }
        if (!$auth->validateCsrf()) {
            ApiResponse::error('CSRF_TOKEN_INVALID', '보안 토큰이 유효하지 않습니다', 403);
            exit;
        }
        if ($roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '관리자 권한이 필요합니다', 403);
            exit;
        }

        /* 동기화 실행: 실제 SmartThings/Tuya API 호출은 V2 서비스 계층 구축 후 연동.
           현재는 기존 IntegrationService 체크포인트 업데이트만 수행. */
        $synced = 0;
        if ($tableExists('Tb_IntProviderAccount') && $tableExists('Tb_IntSyncCheckpoint')) {
            $providerWhere = "a.service_code=? AND a.tenant_id=? AND a.status='ACTIVE'";
            if ($columnExists('Tb_IntProviderAccount', 'is_deleted')) {
                $providerWhere .= " AND ISNULL(a.is_deleted,0)=0";
            }
            $st = $db->prepare("UPDATE c SET c.status='PENDING', c.updated_at=GETDATE()
                                FROM Tb_IntSyncCheckpoint c
                                INNER JOIN Tb_IntProviderAccount a ON a.idx = c.provider_account_idx
                                WHERE {$providerWhere}");
            $st->execute([$serviceCode, $tenantId]);
            $synced = (int)$st->rowCount();
        }
        ApiResponse::success([
            'synced_checkpoints' => $synced,
            'message' => $synced > 0 ? '동기화 요청 완료 (' . $synced . '건)' : '동기화할 활성 계정이 없습니다',
        ], 'OK', '장치 동기화 요청 완료');
        exit;
    }

    ApiResponse::error('UNSUPPORTED_TODO', 'unsupported todo', 400, ['todo' => $todo]);
} catch (InvalidArgumentException $e) {
    ApiResponse::error('INVALID_INPUT', $e->getMessage(), 422);
} catch (Throwable $e) {
    ApiResponse::error(
        'SERVER_ERROR',
        shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : 'Internal error',
        500
    );
}
