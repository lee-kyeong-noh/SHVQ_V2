<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $auth = new AuthService();
    $context = $auth->currentContext();
    if ($context === []) {
        ApiResponse::error('AUTH_REQUIRED', '인증이 필요합니다', 401);
        exit;
    }

    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    $jsonBody = null;
    if (str_contains($contentType, 'application/json')) {
        $rawBody = trim((string)file_get_contents('php://input'));
        if ($rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $jsonBody = $decoded;
            }
        }
    }

    $req = static function (string $key, mixed $default = null) use ($jsonBody): mixed {
        if (array_key_exists($key, $_POST)) {
            return $_POST[$key];
        }
        if (array_key_exists($key, $_GET)) {
            return $_GET[$key];
        }
        if (is_array($jsonBody) && array_key_exists($key, $jsonBody)) {
            return $jsonBody[$key];
        }
        return $default;
    };

    $todoRaw = trim((string)$req('todo', 'list'));
    $todo = strtolower($todoRaw);
    $writeTodos = ['save', 'delete'];

    if (in_array($todo, $writeTodos, true)) {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            ApiResponse::error('METHOD_NOT_ALLOWED', 'POST method required', 405);
            exit;
        }
        if (!$auth->validateCsrf()) {
            ApiResponse::error('CSRF_TOKEN_INVALID', '보안 검증 실패', 403);
            exit;
        }
    }

    $db = DbConnection::get();
    $tableName = 'Tb_WireCheck';

    $tableExists = static function (PDO $pdo, string $table): bool {
        $stmt = $pdo->prepare(
            "SELECT TOP 1 1
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = ?"
        );
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    };

    $columnExists = static function (PDO $pdo, string $table, string $column): bool {
        $stmt = $pdo->prepare(
            "SELECT TOP 1 1
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    };

    $firstExistingColumn = static function (PDO $pdo, string $table, array $candidates) use ($columnExists): ?string {
        foreach ($candidates as $candidate) {
            if ($columnExists($pdo, $table, $candidate)) {
                return $candidate;
            }
        }
        return null;
    };

    $normalizeIdentifier = static function (string $value, string $fallback): string {
        $trimmed = trim($value);
        if ($trimmed === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $trimmed)) {
            return $fallback;
        }
        return $trimmed;
    };

    if (!$tableExists($db, $tableName)) {
        ApiResponse::error('SCHEMA_NOT_READY', 'Tb_WireCheck table is missing', 503);
        exit;
    }

    $toInt = static function (mixed $value, int $default = 0): int {
        if ($value === null || $value === '') {
            return $default;
        }
        return (int)round((float)str_replace(',', '', (string)$value));
    };

    $toFloat = static function (mixed $value, float $default = 0.0): float {
        if ($value === null || $value === '') {
            return $default;
        }
        return (float)str_replace(',', '', (string)$value);
    };

    $toNullableFloat = static function (mixed $value): ?float {
        if ($value === null) {
            return null;
        }
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        return (float)str_replace(',', '', $text);
    };

    $toText = static function (mixed $value, int $maxLen, string $default = ''): string {
        $text = trim((string)$value);
        if ($text === '') {
            $text = $default;
        }
        if (mb_strlen($text, 'UTF-8') > $maxLen) {
            $text = mb_substr($text, 0, $maxLen, 'UTF-8');
        }
        return $text;
    };

    $tenantId = trim((string)($context['tenant_id'] ?? '0'));
    if ($tenantId === '') {
        $tenantId = '0';
    }
    $actorUserPk = (int)($context['user_pk'] ?? 0);

    if (in_array($todo, ['list', 'get'], true)) {
        $requestedGroupId = max(0, $toInt($req('group_id', 0)));
        $targetGroupId = $requestedGroupId;

        if ($targetGroupId <= 0) {
            $stmt = $db->prepare(
                "SELECT TOP 1 group_id
                 FROM dbo.Tb_WireCheck
                 WHERE tenant_id = ? AND group_id IS NOT NULL
                 ORDER BY group_id DESC"
            );
            $stmt->execute([$tenantId]);
            $targetGroupId = (int)($stmt->fetchColumn() ?: 0);
        }

        if ($targetGroupId <= 0) {
            ApiResponse::success([], 'OK', '배관배선 데이터 조회 성공');
            exit;
        }

        $stmt = $db->prepare(
            "SELECT
                idx,
                tenant_id,
                created_by,
                site,
                dtype,
                [struct] AS [struct],
                region,
                division,
                usage,
                max_dist,
                min_dist,
                basement,
                units,
                wiring,
                estimate,
                memo,
                surcharge,
                inline_m,
                fire_room,
                mat_unit,
                group_id,
                created_at,
                updated_at
             FROM dbo.Tb_WireCheck
             WHERE tenant_id = ? AND group_id = ?
             ORDER BY idx ASC"
        );
        $stmt->execute([$tenantId, $targetGroupId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['idx'] = (int)($row['idx'] ?? 0);
            $row['created_by'] = (int)($row['created_by'] ?? 0);
            $row['max_dist'] = round((float)($row['max_dist'] ?? 0), 1);
            $row['min_dist'] = round((float)($row['min_dist'] ?? 0), 1);
            $row['basement'] = (int)($row['basement'] ?? 0);
            $row['units'] = (int)($row['units'] ?? 0);
            $row['wiring'] = round((float)($row['wiring'] ?? 0), 1);
            $row['estimate'] = ($row['estimate'] === null || $row['estimate'] === '') ? null : round((float)$row['estimate'], 1);
            $row['surcharge'] = round((float)($row['surcharge'] ?? 0), 2);
            $row['inline_m'] = (int)($row['inline_m'] ?? 0);
            $row['fire_room'] = (int)($row['fire_room'] ?? 0);
            $row['mat_unit'] = (int)($row['mat_unit'] ?? 0);
            $row['group_id'] = (int)($row['group_id'] ?? 0);
        }
        unset($row);

        ApiResponse::success($rows, 'OK', '배관배선 데이터 조회 성공');
        exit;
    }

    if ($todo === 'all') {
        $stmt = $db->prepare(
            "SELECT
                idx, tenant_id, created_by, site, dtype,
                [struct] AS [struct], region, division, usage,
                max_dist, min_dist, basement, units, wiring,
                estimate, memo, surcharge, inline_m, fire_room,
                mat_unit, group_id, created_at
             FROM dbo.Tb_WireCheck
             WHERE tenant_id = ? AND group_id IS NOT NULL
             ORDER BY group_id DESC, idx ASC"
        );
        $stmt->execute([$tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['idx']       = (int)($row['idx'] ?? 0);
            $row['max_dist']  = round((float)($row['max_dist'] ?? 0), 1);
            $row['min_dist']  = round((float)($row['min_dist'] ?? 0), 1);
            $row['basement']  = (int)($row['basement'] ?? 0);
            $row['units']     = (int)($row['units'] ?? 0);
            $row['wiring']    = round((float)($row['wiring'] ?? 0), 1);
            $row['estimate']  = ($row['estimate'] === null || $row['estimate'] === '') ? null : round((float)$row['estimate'], 1);
            $row['surcharge'] = round((float)($row['surcharge'] ?? 0), 2);
            $row['inline_m']  = (int)($row['inline_m'] ?? 0);
            $row['fire_room'] = (int)($row['fire_room'] ?? 0);
            $row['mat_unit']  = (int)($row['mat_unit'] ?? 0);
            $row['group_id']  = (int)($row['group_id'] ?? 0);
        }
        unset($row);

        ApiResponse::success($rows, 'OK', '배관배선 전체 데이터 조회 성공');
        exit;
    }

    if (in_array($todo, ['groups', 'group_list'], true)) {
        $security = require __DIR__ . '/../../config/security.php';
        $userTable = $normalizeIdentifier((string)($security['auth']['user_table'] ?? 'Tb_Users'), 'Tb_Users');
        $userPkColumn = $normalizeIdentifier((string)($security['auth']['user_pk_column'] ?? 'idx'), 'idx');
        $nameColumn = $firstExistingColumn($db, $userTable, ['name', 'user_name', 'nickname', 'id']);

        $joinSql = '';
        $nameSql = "CONVERT(NVARCHAR(20), g.created_by)";
        if ($nameColumn !== null) {
            $joinSql = ' LEFT JOIN dbo.[' . $userTable . '] u ON u.[' . $userPkColumn . '] = g.created_by ';
            $nameSql = 'ISNULL(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(200), u.[' . $nameColumn . ']))), \'\'), CONVERT(NVARCHAR(20), g.created_by))';
        }

        $sql = "
            SELECT
                g.group_id,
                g.site_count,
                g.created_at,
                {$nameSql} AS created_by_name
            FROM (
                SELECT
                    group_id,
                    COUNT(*) AS site_count,
                    MAX(created_at) AS created_at,
                    MAX(created_by) AS created_by
                FROM dbo.Tb_WireCheck
                WHERE tenant_id = :tenant_id
                  AND group_id IS NOT NULL
                GROUP BY group_id
            ) g
            {$joinSql}
            ORDER BY g.group_id DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId]);
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($groups as &$group) {
            $group['group_id'] = (int)($group['group_id'] ?? 0);
            $group['site_count'] = (int)($group['site_count'] ?? 0);
            $group['created_by_name'] = trim((string)($group['created_by_name'] ?? ''));
        }
        unset($group);

        ApiResponse::success($groups, 'OK', '배관배선 그룹 목록 조회 성공');
        exit;
    }

    if ($todo === 'save') {
        $rowsRaw = $req('rows', []);
        if (is_string($rowsRaw)) {
            $decoded = json_decode($rowsRaw, true);
            if (is_array($decoded)) {
                $rowsRaw = $decoded;
            }
        }
        if (!is_array($rowsRaw)) {
            ApiResponse::error('INVALID_PARAM', 'rows must be array/json', 422);
            exit;
        }
        if (count($rowsRaw) > 1000) {
            ApiResponse::error('INVALID_PARAM', 'rows limit exceeded (max 1000)', 422);
            exit;
        }

        $globalSurcharge = round(max(0.0, $toFloat($req('surcharge', 1.05), 1.05)), 2);
        $globalInline = max(0, $toInt($req('inline_m', 10), 10));
        $globalFireRoom = max(0, $toInt($req('fire_room', 10), 10));
        $globalMatUnit = max(0, $toInt($req('mat_unit', 400), 400));

        $targetGroupId = max(0, $toInt($req('group_id', 0), 0));

        $normalizeRow = static function (array $row) use (
            $toText,
            $toFloat,
            $toInt,
            $toNullableFloat,
            $globalSurcharge,
            $globalInline,
            $globalFireRoom,
            $globalMatUnit
        ): array {
            $site = $toText($row['site'] ?? '', 50);
            $dtype = $toText($row['dtype'] ?? '', 10);
            $struct = $toText($row['struct'] ?? '', 30);
            $region = $toText($row['region'] ?? '지방', 10, '지방');
            $division = strtoupper($toText($row['division'] ?? 'NI', 2, 'NI'));
            if ($division !== 'NI' && $division !== 'RM') {
                $division = 'NI';
            }
            $usage = $toText($row['usage'] ?? '아파트', 20, '아파트');
            $maxDist = round(max(0.0, $toFloat($row['max_dist'] ?? 0, 0.0)), 1);
            $minDist = round(max(0.0, $toFloat($row['min_dist'] ?? 0, 0.0)), 1);
            $basement = max(0, $toInt($row['basement'] ?? 0, 0));
            $units = max(0, $toInt($row['units'] ?? 0, 0));
            $wiring = round(max(0.0, $toFloat($row['wiring'] ?? 0.9, 0.9)), 1);

            $estimateRaw = $toNullableFloat($row['estimate'] ?? null);
            $estimate = $estimateRaw === null ? null : round(max(0.0, $estimateRaw), 1);

            $memoText = $toText($row['memo'] ?? '', 200);
            $memo = $memoText === '' ? null : $memoText;

            $surcharge = round(max(0.0, $toFloat($row['surcharge'] ?? $globalSurcharge, $globalSurcharge)), 2);
            $inlineM = max(0, $toInt($row['inline_m'] ?? $globalInline, $globalInline));
            $fireRoom = max(0, $toInt($row['fire_room'] ?? $globalFireRoom, $globalFireRoom));
            $matUnit = max(0, $toInt($row['mat_unit'] ?? $globalMatUnit, $globalMatUnit));

            return [
                'site' => $site,
                'dtype' => $dtype,
                'struct' => $struct,
                'region' => $region,
                'division' => $division,
                'usage' => $usage,
                'max_dist' => $maxDist,
                'min_dist' => $minDist,
                'basement' => $basement,
                'units' => $units,
                'wiring' => $wiring,
                'estimate' => $estimate,
                'memo' => $memo,
                'surcharge' => $surcharge,
                'inline_m' => $inlineM,
                'fire_room' => $fireRoom,
                'mat_unit' => $matUnit,
            ];
        };

        $db->beginTransaction();
        try {
            if ($targetGroupId <= 0) {
                $stmt = $db->prepare(
                    "SELECT ISNULL(MAX(group_id), 0) + 1 AS next_group_id
                     FROM dbo.Tb_WireCheck WITH (UPDLOCK, HOLDLOCK)
                     WHERE tenant_id = ?"
                );
                $stmt->execute([$tenantId]);
                $targetGroupId = (int)($stmt->fetchColumn() ?: 1);
            }

            $deleteStmt = $db->prepare("DELETE FROM dbo.Tb_WireCheck WHERE tenant_id = ? AND group_id = ?");
            $deleteStmt->execute([$tenantId, $targetGroupId]);

            $insertStmt = $db->prepare(
                "INSERT INTO dbo.Tb_WireCheck (
                    tenant_id,
                    created_by,
                    site,
                    dtype,
                    [struct],
                    region,
                    division,
                    usage,
                    max_dist,
                    min_dist,
                    basement,
                    units,
                    wiring,
                    estimate,
                    memo,
                    surcharge,
                    inline_m,
                    fire_room,
                    mat_unit,
                    group_id,
                    created_at,
                    updated_at
                ) VALUES (
                    :tenant_id,
                    :created_by,
                    :site,
                    :dtype,
                    :struct,
                    :region,
                    :division,
                    :usage,
                    :max_dist,
                    :min_dist,
                    :basement,
                    :units,
                    :wiring,
                    :estimate,
                    :memo,
                    :surcharge,
                    :inline_m,
                    :fire_room,
                    :mat_unit,
                    :group_id,
                    GETDATE(),
                    NULL
                )"
            );

            $inserted = 0;
            foreach ($rowsRaw as $idx => $row) {
                if (!is_array($row)) {
                    throw new InvalidArgumentException('rows[' . $idx . '] must be object');
                }
                $n = $normalizeRow($row);

                $insertStmt->execute([
                    'tenant_id' => $tenantId,
                    'created_by' => $actorUserPk,
                    'site' => $n['site'],
                    'dtype' => $n['dtype'],
                    'struct' => $n['struct'],
                    'region' => $n['region'],
                    'division' => $n['division'],
                    'usage' => $n['usage'],
                    'max_dist' => $n['max_dist'],
                    'min_dist' => $n['min_dist'],
                    'basement' => $n['basement'],
                    'units' => $n['units'],
                    'wiring' => $n['wiring'],
                    'estimate' => $n['estimate'],
                    'memo' => $n['memo'],
                    'surcharge' => $n['surcharge'],
                    'inline_m' => $n['inline_m'],
                    'fire_room' => $n['fire_room'],
                    'mat_unit' => $n['mat_unit'],
                    'group_id' => $targetGroupId,
                ]);
                $inserted++;
            }

            $db->commit();

            ApiResponse::success([
                'group_id' => $targetGroupId,
                'count' => $inserted,
            ], 'OK', '배관배선 데이터 저장 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'delete') {
        $groupId = max(0, $toInt($req('group_id', 0), 0));
        if ($groupId <= 0) {
            ApiResponse::error('INVALID_PARAM', 'group_id is required', 422);
            exit;
        }

        $stmt = $db->prepare("DELETE FROM dbo.Tb_WireCheck WHERE tenant_id = ? AND group_id = ?");
        $stmt->execute([$tenantId, $groupId]);
        $deleted = $stmt->rowCount();

        ApiResponse::success([
            'group_id' => $groupId,
            'deleted' => $deleted,
        ], 'OK', '배관배선 그룹 삭제 성공');
        exit;
    }

    ApiResponse::error('UNSUPPORTED_TODO', '지원하지 않는 todo: ' . $todoRaw, 400, ['todo' => $todoRaw]);
} catch (InvalidArgumentException $e) {
    ApiResponse::error('INVALID_PARAM', $e->getMessage(), 422);
} catch (Throwable $e) {
    error_log('[WireCheck API] ' . $e->getMessage());
    ApiResponse::error(
        'SERVER_ERROR',
        shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : '서버 오류가 발생했습니다',
        500
    );
}
