<?php
declare(strict_types=1);
/**
 * SHVQ V2 — HeadOffice API
 * DB: CSM_C004732_V2
 *
 * todo=list         GET  : 본사 목록(검색/기간/정렬/페이지)
 * todo=detail       GET  : 본사 상세(+첨부)
 * todo=check_dup    GET  : 사업자번호 중복확인
 * todo=insert       POST : 본사 등록
 * todo=update       POST : 본사 수정
 * todo=bulk_update  POST : 본사 다건 일괄수정
 * todo=restore      POST : 본사 복구
 * todo=delete       POST : 본사 다건 삭제
 * todo=delete_attach POST: 본사 첨부 삭제
 * todo=org_folder_list      GET  : 본사 조직도 폴더 목록
 * todo=org_folder_insert    POST : 본사 조직도 폴더 추가
 * todo=org_folder_update    POST : 본사 조직도 폴더 이름 수정
 * todo=org_folder_delete    POST : 본사 조직도 폴더 삭제
 * todo=org_folder_reorder   POST : 본사 조직도 폴더 정렬 변경
 * todo=assign_branch_folder POST : 사업장-조직도 폴더 연결
 * todo=reorder_branches     POST : 연결 사업장 순서 변경(field1)
 * todo=update_settings      POST : 본사 설정값 저장
 * todo=create_head_from_member POST : 사업장 기반 본사 자동 생성
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../dist_library/saas/security/FmsInputValidator.php';
require_once __DIR__ . '/../../dist_library/saas/ShadowWriteQueueService.php';
require_once __DIR__ . '/../../dist_library/saas/DevLogService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $auth = new AuthService();
    $context = $auth->currentContext();
    if ($context === []) {
        ApiResponse::error('AUTH_REQUIRED', '인증이 필요합니다', 401);
        exit;
    }

    $todoRaw = trim((string)($_GET['todo'] ?? $_POST['todo'] ?? ''));
    $todo = strtolower($todoRaw);
    $db = DbConnection::get();
    $security = require __DIR__ . '/../../config/security.php';

    $tableExistsCache = [];
    $tableExists = static function (PDO $pdo, string $table) use (&$tableExistsCache): bool {
        $key = strtolower($table);
        if (array_key_exists($key, $tableExistsCache)) {
            return (bool)$tableExistsCache[$key];
        }

        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.TABLES\n            WHERE TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'\n        ");
        $stmt->execute([$table]);
        $exists = (int)$stmt->fetchColumn() > 0;
        $tableExistsCache[$key] = $exists;
        return $exists;
    };

    $columnExistsCache = [];
    $columnExists = static function (PDO $pdo, string $table, string $column) use (&$columnExistsCache): bool {
        $key = strtolower($table . '.' . $column);
        if (array_key_exists($key, $columnExistsCache)) {
            return (bool)$columnExistsCache[$key];
        }

        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.COLUMNS\n            WHERE TABLE_NAME = ? AND COLUMN_NAME = ?\n        ");
        $stmt->execute([$table, $column]);
        $exists = (int)$stmt->fetchColumn() > 0;
        $columnExistsCache[$key] = $exists;
        return $exists;
    };

    $firstExistingColumn = static function (PDO $pdo, callable $columnExistsFn, string $table, array $candidates): ?string {
        foreach ($candidates as $candidate) {
            $col = trim((string)$candidate);
            if ($col !== '' && $columnExistsFn($pdo, $table, $col)) {
                return $col;
            }
        }
        return null;
    };

    $appendUnique = static function (array &$bucket, string $value): void {
        $trimmed = trim($value);
        if ($trimmed === '' || in_array($trimmed, $bucket, true)) {
            return;
        }
        $bucket[] = $trimmed;
    };

    $encryptMethods = [];
    foreach ([
        defined('ENCRYPT_METHOD') ? (string)constant('ENCRYPT_METHOD') : '',
        (string)shvEnv('ENCRYPT_METHOD', ''),
        (string)shvEnv('APP_ENCRYPT_METHOD', ''),
        'AES-256-CBC',
    ] as $method) {
        $appendUnique($encryptMethods, (string)$method);
    }

    $encryptKeys = [];
    foreach ([
        defined('ENCRYPT_KEY') ? (string)constant('ENCRYPT_KEY') : '',
        (string)shvEnv('ENCRYPT_KEY', ''),
        (string)shvEnv('APP_ENCRYPT_KEY', ''),
        (string)shvEnv('SECRET_KEY', ''),
        // legacy SHV key fallback (V1 데이터 복호화 호환)
        'SHV_ERP_2024_S3cureK3y!@#',
    ] as $key) {
        $appendUnique($encryptKeys, (string)$key);
    }

    $decodeLegacyCipherPayload = static function (string $value): ?array {
        $candidates = [];
        $pushCandidate = static function (array &$bucket, string $candidate): void {
            if ($candidate === '' || in_array($candidate, $bucket, true)) {
                return;
            }
            $bucket[] = $candidate;
        };

        $trimmed = trim($value);
        if ($trimmed !== '') {
            $pushCandidate($candidates, $trimmed);

            $base64Variants = [$trimmed];
            $urlSafe = strtr($trimmed, '-_', '+/');
            if ($urlSafe !== $trimmed) {
                $base64Variants[] = $urlSafe;
            }

            foreach ($base64Variants as $variant) {
                $padding = strlen($variant) % 4;
                $padded = $padding === 0 ? $variant : ($variant . str_repeat('=', 4 - $padding));
                foreach ([$variant, $padded] as $encoded) {
                    $decoded = base64_decode($encoded, true);
                    if (is_string($decoded) && $decoded !== '') {
                        $pushCandidate($candidates, $decoded);
                    }
                }
            }
        }

        foreach ($candidates as $candidate) {
            $separatorPos = strpos($candidate, '::');
            if ($separatorPos === false) {
                continue;
            }

            $iv = substr($candidate, 0, $separatorPos);
            $cipher = substr($candidate, $separatorPos + 2);
            if ($iv === '' || $cipher === '') {
                continue;
            }

            return [$iv, $cipher];
        }

        return null;
    };

    $decryptLegacyCipher = static function (?string $rawValue) use ($encryptMethods, $encryptKeys, $decodeLegacyCipherPayload): ?string {
        $value = trim((string)$rawValue);
        if ($value === '' || $encryptMethods === [] || $encryptKeys === []) {
            return null;
        }

        $payload = $decodeLegacyCipherPayload($value);
        if (!is_array($payload) || count($payload) !== 2) {
            return null;
        }

        [$iv, $cipher] = $payload;

        foreach ($encryptMethods as $method) {
            $ivLength = openssl_cipher_iv_length($method);
            if (!is_int($ivLength) || $ivLength <= 0 || strlen($iv) !== $ivLength) {
                continue;
            }

            foreach ($encryptKeys as $key) {
                $plain = openssl_decrypt($cipher, $method, $key, 0, $iv);
                if (!is_string($plain) || $plain === '') {
                    $plain = openssl_decrypt($cipher, $method, $key, OPENSSL_RAW_DATA, $iv);
                }
                if (!is_string($plain) || $plain === '') {
                    continue;
                }

                if (function_exists('mb_check_encoding') && !mb_check_encoding($plain, 'UTF-8')) {
                    continue;
                }
                if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $plain) === 1) {
                    continue;
                }

                return trim($plain);
            }
        }

        return null;
    };

    $decryptHeadOfficeSensitive = static function (array $row, callable $decryptFn): array {
        foreach (['identity_number', 'corporate_number', 'corporation_number', 'corp_number', 'identity_no'] as $column) {
            if (!array_key_exists($column, $row)) {
                continue;
            }
            $rawValue = trim((string)$row[$column]);
            if ($rawValue === '') {
                continue;
            }

            $decrypted = $decryptFn($rawValue);
            if (is_string($decrypted) && $decrypted !== '') {
                $row[$column] = $decrypted;
            }
        }

        return $row;
    };

    $loadHeadOfficeAttachments = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, int $headIdx): array {
        if ($headIdx <= 0 || !$tableExistsFn($pdo, 'Tb_FileAttach')) {
            return [];
        }

        $linkColumn = '';
        foreach (['head_idx', 'table_idx', 'ref_idx', 'target_idx', 'parent_idx'] as $candidate) {
            if ($columnExistsFn($pdo, 'Tb_FileAttach', $candidate)) {
                $linkColumn = $candidate;
                break;
            }
        }
        if ($linkColumn === '') {
            return [];
        }

        $where = ["{$linkColumn} = ?"];
        $params = [$headIdx];

        if ($columnExistsFn($pdo, 'Tb_FileAttach', 'table_name')) {
            $where[] = '(table_name = ? OR table_name = ?)';
            $params[] = 'Tb_HeadOffice';
            $params[] = 'head_office';
        }
        if ($columnExistsFn($pdo, 'Tb_FileAttach', 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }

        $nameExpr = $columnExistsFn($pdo, 'Tb_FileAttach', 'file_name') ? 'file_name'
            : ($columnExistsFn($pdo, 'Tb_FileAttach', 'origin_name') ? 'origin_name' : "CAST('' AS NVARCHAR(255))");
        $pathExpr = $columnExistsFn($pdo, 'Tb_FileAttach', 'file_path') ? 'file_path'
            : ($columnExistsFn($pdo, 'Tb_FileAttach', 'path') ? 'path' : "CAST('' AS NVARCHAR(500))");

        $sql = "SELECT idx, {$nameExpr} AS file_name, {$pathExpr} AS file_path"
            . ($columnExistsFn($pdo, 'Tb_FileAttach', 'file_size') ? ', file_size' : ', CAST(0 AS INT) AS file_size')
            . ($columnExistsFn($pdo, 'Tb_FileAttach', 'created_at') ? ', created_at' : ', NULL AS created_at')
            . " FROM Tb_FileAttach WHERE " . implode(' AND ', $where) . ' ORDER BY idx DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    };

    $loadHeadOfficeByIdx = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, callable $attachLoader, int $idx) use ($decryptHeadOfficeSensitive, $decryptLegacyCipher): ?array {
        if ($idx <= 0) {
            return null;
        }

        $where = ['h.idx = ?'];
        if ($columnExistsFn($pdo, 'Tb_HeadOffice', 'is_deleted')) {
            $where[] = 'ISNULL(h.is_deleted,0)=0';
        }

        $employeeNameExpr = $tableExistsFn($pdo, 'Tb_Employee')
            ? '(SELECT TOP 1 name FROM Tb_Employee WHERE idx = h.employee_idx)'
            : "CAST('' AS NVARCHAR(120))";
        $groupNameExpr = $tableExistsFn($pdo, 'Tb_MemberGroup')
            ? '(SELECT TOP 1 name FROM Tb_MemberGroup WHERE idx = h.group_idx)'
            : "CAST('' AS NVARCHAR(120))";

        $sql = "SELECT h.*, {$employeeNameExpr} AS employee_name, {$groupNameExpr} AS group_name\n"
             . 'FROM Tb_HeadOffice h WHERE ' . implode(' AND ', $where);

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$idx]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $row = $decryptHeadOfficeSensitive($row, $decryptLegacyCipher);
        $row['attachments'] = $attachLoader($pdo, $tableExistsFn, $columnExistsFn, $idx);
        return $row;
    };

    $findByCardNumber = static function (PDO $pdo, callable $columnExistsFn, string $cardDigits, int $excludeIdx = 0): ?array {
        $digits = preg_replace('/\D+/', '', $cardDigits) ?? '';
        if ($digits === '') {
            return null;
        }

        $where = ["REPLACE(REPLACE(ISNULL(h.card_number,''),'-',''),' ','') = ?"];
        $params = [$digits];

        if ($excludeIdx > 0) {
            $where[] = 'h.idx <> ?';
            $params[] = $excludeIdx;
        }
        if ($columnExistsFn($pdo, 'Tb_HeadOffice', 'is_deleted')) {
            $where[] = 'ISNULL(h.is_deleted,0)=0';
        }

        $stmt = $pdo->prepare('SELECT TOP 1 h.idx, h.name, h.ceo, h.tel, h.address, h.card_number FROM Tb_HeadOffice h WHERE ' . implode(' AND ', $where) . ' ORDER BY h.idx DESC');
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    };

    $resolveEmployeeIdx = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, int $employeeIdx, string $employeeName): int {
        if ($employeeIdx > 0) {
            return $employeeIdx;
        }

        $employeeName = trim($employeeName);
        if ($employeeName === '' || !$tableExistsFn($pdo, 'Tb_Employee') || !$columnExistsFn($pdo, 'Tb_Employee', 'name')) {
            return 0;
        }

        $where = ['name = ?'];
        if ($columnExistsFn($pdo, 'Tb_Employee', 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }

        $stmt = $pdo->prepare('SELECT TOP 1 idx FROM Tb_Employee WHERE ' . implode(' AND ', $where) . ' ORDER BY idx DESC');
        $stmt->execute([$employeeName]);
        return (int)$stmt->fetchColumn();
    };

    $nextHeadNumber = static function (PDO $pdo, callable $columnExistsFn): string {
        $prefix = 'HO' . date('ymd');
        if (!$columnExistsFn($pdo, 'Tb_HeadOffice', 'head_number')) {
            return $prefix . '0001';
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Tb_HeadOffice WHERE head_number LIKE ?");
        $stmt->execute([$prefix . '%']);
        $seq = (int)$stmt->fetchColumn() + 1;
        return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    };

    $writeSvcAuditLog = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, string $api, string $todoName, array $contextData): void {
        if (!$tableExistsFn($pdo, 'Tb_SvcAuditLog')) {
            return;
        }

        $columns = [];
        $values = [];

        $add = static function (string $column, mixed $value) use (&$columns, &$values, $pdo, $columnExistsFn): void {
            if ($columnExistsFn($pdo, 'Tb_SvcAuditLog', $column)) {
                $columns[] = $column;
                $values[] = $value;
            }
        };

        $payloadJson = json_encode($contextData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payloadJson)) {
            $payloadJson = '{}';
        }

        $add('service_code', (string)($contextData['service_code'] ?? 'shvq'));
        $add('tenant_id', (int)($contextData['tenant_id'] ?? 0));
        $add('api_name', $api);
        $add('todo', $todoName);
        $add('target_table', (string)($contextData['target_table'] ?? 'Tb_HeadOffice'));
        $add('target_idx', (int)($contextData['target_idx'] ?? 0));
        $add('actor_user_pk', (int)($contextData['actor_user_pk'] ?? 0));
        $add('actor_login_id', (string)($contextData['actor_login_id'] ?? ''));
        $add('detail_json', $payloadJson);
        $add('status', (string)($contextData['status'] ?? 'SUCCESS'));
        $add('message', (string)($contextData['message'] ?? ''));
        $add('created_at', date('Y-m-d H:i:s'));
        $add('regdate', date('Y-m-d H:i:s'));

        if ($columns === []) {
            return;
        }

        $ph = implode(', ', array_fill(0, count($columns), '?'));
        $sql = 'INSERT INTO Tb_SvcAuditLog (' . implode(', ', $columns) . ') VALUES (' . $ph . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
    };

    $enqueueShadow = static function (PDO $pdo, array $securityCfg, array $contextData, string $todoName, string $targetTable, int $targetIdx, array $payload = []): int {
        try {
            $svc = new ShadowWriteQueueService($pdo, $securityCfg);
            return $svc->enqueueJob([
                'service_code' => (string)($contextData['service_code'] ?? 'shvq'),
                'tenant_id' => (int)($contextData['tenant_id'] ?? 0),
                'api' => 'dist_process/saas/HeadOffice.php',
                'todo' => $todoName,
                'target_table' => $targetTable,
                'target_idx' => $targetIdx,
                'actor_user_pk' => (int)($contextData['user_pk'] ?? 0),
                'actor_login_id' => (string)($contextData['login_id'] ?? ''),
                'requested_at' => date('c'),
                'payload' => $payload,
            ], 'PENDING', 0);
        } catch (Throwable) {
            return 0;
        }
    };

    $normalizeUploadFiles = static function (array $fileBag, string $fieldName): array {
        $files = [];
        foreach ([$fieldName, $fieldName . '[]'] as $candidate) {
            if (!isset($fileBag[$candidate]) || !is_array($fileBag[$candidate])) {
                continue;
            }
            $entry = $fileBag[$candidate];
            if (is_array($entry['name'] ?? null)) {
                $total = count($entry['name']);
                for ($i = 0; $i < $total; $i++) {
                    $files[] = [
                        'name' => (string)($entry['name'][$i] ?? ''),
                        'tmp_name' => (string)($entry['tmp_name'][$i] ?? ''),
                        'size' => (int)($entry['size'][$i] ?? 0),
                        'error' => (int)($entry['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                        'type' => (string)($entry['type'][$i] ?? ''),
                    ];
                }
            } else {
                $files[] = [
                    'name' => (string)($entry['name'] ?? ''),
                    'tmp_name' => (string)($entry['tmp_name'] ?? ''),
                    'size' => (int)($entry['size'] ?? 0),
                    'error' => (int)($entry['error'] ?? UPLOAD_ERR_NO_FILE),
                    'type' => (string)($entry['type'] ?? ''),
                ];
            }
        }
        return $files;
    };

    $saveAttachments = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, callable $normalizeFn, int $headIdx, array $fileBag, int $actorUserPk): array {
        $files = $normalizeFn($fileBag, 'attach');
        if ($headIdx <= 0 || $files === []) {
            return ['requested' => count($files), 'uploaded' => 0, 'rejected' => 0, 'items' => []];
        }

        $maxBytes = 10 * 1024 * 1024;
        $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'hwp'];
        $month = date('Ym');
        $relativeDir = 'uploads/head_office/' . $month;
        $absoluteDir = dirname(__DIR__, 2) . '/' . $relativeDir;

        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            return [
                'requested' => count($files),
                'uploaded' => 0,
                'rejected' => count($files),
                'items' => [['status' => 'error', 'reason' => 'upload_dir_create_failed']],
            ];
        }

        $results = [];
        $uploaded = 0;
        $rejected = 0;

        foreach ($files as $file) {
            $name = trim((string)($file['name'] ?? ''));
            $tmp = (string)($file['tmp_name'] ?? '');
            $size = (int)($file['size'] ?? 0);
            $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            $mime = trim((string)($file['type'] ?? ''));

            if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
                $rejected++;
                $results[] = ['status' => 'rejected', 'name' => $name, 'reason' => 'upload_error', 'error_code' => $error];
                continue;
            }
            if ($size <= 0 || $size > $maxBytes) {
                $rejected++;
                $results[] = ['status' => 'rejected', 'name' => $name, 'reason' => 'file_size_invalid', 'size' => $size];
                continue;
            }

            $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? '';
            $safe = trim($safe) === '' ? 'file' : trim($safe);
            $ext = strtolower((string)pathinfo($safe, PATHINFO_EXTENSION));
            if ($ext === '' || !in_array($ext, $allowedExt, true)) {
                $rejected++;
                $results[] = ['status' => 'rejected', 'name' => $name, 'reason' => 'file_ext_not_allowed'];
                continue;
            }

            $storedName = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $relativePath = $relativeDir . '/' . $storedName;
            $absolutePath = $absoluteDir . '/' . $storedName;

            if (!move_uploaded_file($tmp, $absolutePath)) {
                $rejected++;
                $results[] = ['status' => 'rejected', 'name' => $name, 'reason' => 'move_uploaded_file_failed'];
                continue;
            }

            $uploaded++;
            $result = ['status' => 'uploaded', 'name' => $name, 'stored_name' => $storedName, 'path' => $relativePath, 'size' => $size];

            if ($tableExistsFn($pdo, 'Tb_FileAttach')) {
                $columns = [];
                $values = [];
                $add = static function (string $column, mixed $value) use (&$columns, &$values, $pdo, $columnExistsFn): void {
                    if ($columnExistsFn($pdo, 'Tb_FileAttach', $column)) {
                        $columns[] = $column;
                        $values[] = $value;
                    }
                };

                $add('table_name', 'Tb_HeadOffice');
                $add('target_table', 'Tb_HeadOffice');
                $add('table_idx', $headIdx);
                $add('head_idx', $headIdx);
                $add('ref_idx', $headIdx);
                $add('target_idx', $headIdx);
                $add('file_name', $name);
                $add('origin_name', $name);
                $add('save_name', $storedName);
                $add('stored_name', $storedName);
                $add('file_path', $relativePath);
                $add('path', $relativePath);
                $add('mime_type', $mime);
                $add('file_ext', $ext);
                $add('file_size', $size);
                $add('created_by', $actorUserPk);
                $add('is_deleted', 0);
                $add('created_at', date('Y-m-d H:i:s'));
                $add('regdate', date('Y-m-d H:i:s'));

                if ($columns !== []) {
                    $ph = implode(', ', array_fill(0, count($columns), '?'));
                    $sql = 'INSERT INTO Tb_FileAttach (' . implode(', ', $columns) . ') VALUES (' . $ph . ')';
                    try {
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($values);
                    } catch (Throwable $e) {
                        $result['meta_saved'] = false;
                        $result['meta_error'] = $e->getMessage();
                    }
                }
            }

            $results[] = $result;
        }

        return ['requested' => count($files), 'uploaded' => $uploaded, 'rejected' => $rejected, 'items' => $results];
    };

    if (!$tableExists($db, 'Tb_HeadOffice')) {
        ApiResponse::error('SCHEMA_NOT_READY', 'Tb_HeadOffice table is missing', 503);
        exit;
    }

    $writeTodos = [
        'insert', 'update', 'bulk_update', 'restore', 'delete', 'delete_attach',
        'org_folder_insert', 'org_folder_update', 'org_folder_delete', 'org_folder_reorder', 'assign_branch_folder',
        'reorder_branches', 'update_settings', 'create_head_from_member',
    ];
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

    $roleLevel = (int)($context['role_level'] ?? 0);
    $hasSoftDelete = $columnExists($db, 'Tb_HeadOffice', 'is_deleted');
    $serviceCode = (string)($context['service_code'] ?? 'shvq');
    $tenantId = (int)($context['tenant_id'] ?? 0);
    $orgFolderTable = 'Tb_HeadOrgFolder';

    $loadOrgFolderByIdx = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, string $table, int $idx): ?array {
        if ($idx <= 0 || !$tableExistsFn($pdo, $table)) {
            return null;
        }

        $where = ['idx = ?'];
        if ($columnExistsFn($pdo, $table, 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }

        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE " . implode(' AND ', $where));
        $stmt->execute([$idx]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    };

    $assertHeadExists = static function (PDO $pdo, callable $tableExistsFn, callable $columnExistsFn, int $headIdx): void {
        if ($headIdx <= 0) {
            throw new InvalidArgumentException('head_idx is required');
        }
        if (!$tableExistsFn($pdo, 'Tb_HeadOffice')) {
            throw new InvalidArgumentException('Tb_HeadOffice table is missing');
        }

        $where = ['idx = ?'];
        if ($columnExistsFn($pdo, 'Tb_HeadOffice', 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM Tb_HeadOffice WHERE ' . implode(' AND ', $where));
        $stmt->execute([$headIdx]);
        if ((int)$stmt->fetchColumn() < 1) {
            throw new InvalidArgumentException('head_idx is invalid');
        }
    };

    if ($todo === 'list') {
        $search = trim((string)($_GET['search'] ?? ''));
        $page = max(1, (int)($_GET['p'] ?? 1));
        $limit = min(500, max(1, (int)($_GET['limit'] ?? 100)));

        $dsName = trim((string)($_GET['ds_name'] ?? ''));
        $dsCeo = trim((string)($_GET['ds_ceo'] ?? ''));
        $dsCard = trim((string)($_GET['ds_card'] ?? ''));
        $dsTel = trim((string)($_GET['ds_tel'] ?? ''));
        $dsAddr = trim((string)($_GET['ds_addr'] ?? ''));
        $dsHeadNumber = trim((string)($_GET['ds_head_number'] ?? ''));
        $dsEmployee = trim((string)($_GET['ds_employee'] ?? ''));
        $dsType = trim((string)($_GET['ds_type'] ?? ''));
        $dsContract = trim((string)($_GET['ds_contract'] ?? ''));
        $dateFrom = trim((string)($_GET['date_from'] ?? ''));
        $dateTo = trim((string)($_GET['date_to'] ?? ''));

        $sortRaw = strtolower(trim((string)($_GET['sort'] ?? 'idx')));
        $orderRaw = strtolower(trim((string)($_GET['order'] ?? 'desc')));

        $sortMap = [
            'idx' => 'h.idx',
            'name' => 'h.name',
            'ceo' => 'h.ceo',
            'head_number' => 'h.head_number',
            'registered_date' => $columnExists($db, 'Tb_HeadOffice', 'registered_date') ? 'h.registered_date' : 'h.idx',
        ];
        $sortExpr = $sortMap[$sortRaw] ?? 'h.idx';
        $orderExpr = $orderRaw === 'asc' ? 'ASC' : 'DESC';

        $where = ['1=1'];
        $params = [];

        if ($hasSoftDelete) {
            $where[] = 'ISNULL(h.is_deleted,0)=0';
        }

        if ($search !== '') {
            $sp = '%' . $search . '%';
            $where[] = '(h.name LIKE ? OR h.ceo LIKE ? OR h.card_number LIKE ? OR h.tel LIKE ? OR h.address LIKE ? OR h.head_number LIKE ?)';
            array_push($params, $sp, $sp, $sp, $sp, $sp, $sp);
        }

        if ($dsName !== '') { $where[] = 'h.name LIKE ?'; $params[] = '%' . $dsName . '%'; }
        if ($dsCeo !== '') { $where[] = 'h.ceo LIKE ?'; $params[] = '%' . $dsCeo . '%'; }
        if ($dsCard !== '') { $where[] = 'h.card_number LIKE ?'; $params[] = '%' . $dsCard . '%'; }
        if ($dsTel !== '') { $where[] = 'h.tel LIKE ?'; $params[] = '%' . $dsTel . '%'; }
        if ($dsAddr !== '') { $where[] = 'h.address LIKE ?'; $params[] = '%' . $dsAddr . '%'; }
        if ($dsHeadNumber !== '') { $where[] = 'h.head_number LIKE ?'; $params[] = '%' . $dsHeadNumber . '%'; }
        if ($dsEmployee !== '') { $where[] = 'h.employee_idx = ?'; $params[] = (int)$dsEmployee; }
        if ($dsType !== '') { $where[] = 'h.head_type = ?'; $params[] = $dsType; }
        if ($dsContract !== '') { $where[] = 'h.cooperation_contract LIKE ?'; $params[] = '%' . $dsContract . '%'; }

        if ($dateFrom !== '' && $columnExists($db, 'Tb_HeadOffice', 'registered_date')) {
            $where[] = 'CAST(h.registered_date AS DATE) >= ?';
            $params[] = $dateFrom;
        }
        if ($dateTo !== '' && $columnExists($db, 'Tb_HeadOffice', 'registered_date')) {
            $where[] = 'CAST(h.registered_date AS DATE) <= ?';
            $params[] = $dateTo;
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $db->prepare("SELECT COUNT(*) FROM Tb_HeadOffice h {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $rowFrom = ($page - 1) * $limit + 1;
        $rowTo = $rowFrom + $limit - 1;
        $pageParams = array_merge($params, [$rowFrom, $rowTo]);

        $employeeNameExpr = $tableExists($db, 'Tb_Employee')
            ? '(SELECT TOP 1 name FROM Tb_Employee WHERE idx = h.employee_idx)'
            : "CAST('' AS NVARCHAR(120))";
        $groupNameExpr = $tableExists($db, 'Tb_MemberGroup')
            ? '(SELECT TOP 1 name FROM Tb_MemberGroup WHERE idx = h.group_idx)'
            : "CAST('' AS NVARCHAR(120))";

        $listSql = "SELECT * FROM (\n"
            . "  SELECT\n"
            . "    h.idx, h.name, h.head_number, h.ceo, h.card_number,\n"
            . "    h.tel, h.email, h.address, h.address_detail, h.zipcode,\n"
            . "    h.head_type, h.head_structure, h.cooperation_contract,\n"
            . "    h.used_estimate, h.contract_type, h.main_business,\n"
            . "    h.memo, h.registered_date, h.employee_idx,\n"
            . "    {$employeeNameExpr} AS employee_name,\n"
            . "    {$groupNameExpr} AS group_name,\n"
            . "    ROW_NUMBER() OVER (ORDER BY {$sortExpr} {$orderExpr}, h.idx DESC) AS rn\n"
            . "  FROM Tb_HeadOffice h {$whereSql}\n"
            . ") t WHERE t.rn BETWEEN ? AND ?";

        $stmt = $db->prepare($listSql);
        $stmt->execute($pageParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ApiResponse::success([
            'data' => is_array($rows) ? $rows : [],
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $limit)),
            'limit' => $limit,
            'sort' => $sortRaw,
            'order' => $orderExpr,
        ], 'OK', '본사 목록 조회 성공');
        exit;
    }

    if ($todo === 'detail') {
        $idx = (int)($_GET['idx'] ?? 0);
        if ($idx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'idx is required', 422);
            exit;
        }

        $row = $loadHeadOfficeByIdx($db, $tableExists, $columnExists, $loadHeadOfficeAttachments, $idx);
        if ($row === null) {
            ApiResponse::error('NOT_FOUND', '본사를 찾을 수 없습니다', 404);
            exit;
        }

        ApiResponse::success($row, 'OK', '본사 상세 조회 성공');
        exit;
    }

    if ($todo === 'check_dup') {
        $cardNumber = trim((string)($_GET['card_number'] ?? $_POST['card_number'] ?? ''));
        $excludeIdx = (int)($_GET['exclude_idx'] ?? $_POST['exclude_idx'] ?? $_GET['idx'] ?? $_POST['idx'] ?? 0);

        if ($cardNumber !== '') {
            FmsInputValidator::bizNumber($cardNumber, 'card_number', false);
        }

        $existing = $cardNumber === '' ? null : $findByCardNumber($db, $columnExists, $cardNumber, $excludeIdx);

        echo json_encode([
            'success' => true,
            'ok' => true,
            'code' => 'OK',
            'message' => '사업자번호 중복검사 완료',
            'exists' => is_array($existing),
            'data' => $existing,
            'card_number' => preg_replace('/\D+/', '', $cardNumber) ?? '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($todo === 'org_folder_list') {
        if (!$tableExists($db, $orgFolderTable)) {
            ApiResponse::error('SCHEMA_NOT_READY', $orgFolderTable . ' table is missing', 503);
            exit;
        }

        $headIdx = FmsInputValidator::int($_GET, 'head_idx', true, 1);
        $assertHeadExists($db, $tableExists, $columnExists, (int)$headIdx);

        $where = ['head_idx = ?'];
        $params = [(int)$headIdx];
        if ($columnExists($db, $orgFolderTable, 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }

        $stmt = $db->prepare(
            "SELECT idx, ISNULL(parent_idx, 0) AS parent_idx, name, depth, sort_order, head_idx\n"
            . "FROM {$orgFolderTable} WHERE " . implode(' AND ', $where) . "\n"
            . 'ORDER BY depth ASC, ISNULL(parent_idx,0) ASC, sort_order ASC, idx ASC'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ApiResponse::success([
            'head_idx' => (int)$headIdx,
            'data' => is_array($rows) ? $rows : [],
        ], 'OK', '조직도 폴더 목록 조회 성공');
        exit;
    }

    if ($todo === 'org_folder_insert') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 2, 'current' => $roleLevel]);
            exit;
        }
        if (!$tableExists($db, $orgFolderTable)) {
            ApiResponse::error('SCHEMA_NOT_READY', $orgFolderTable . ' table is missing', 503);
            exit;
        }

        $headIdx = FmsInputValidator::int($_POST, 'head_idx', true, 1);
        $parentIdxRaw = FmsInputValidator::int($_POST, 'parent_idx', false, 0);
        $parentIdx = $parentIdxRaw !== null && $parentIdxRaw > 0 ? (int)$parentIdxRaw : null;
        $name = FmsInputValidator::string($_POST, 'name', 120, true);

        $assertHeadExists($db, $tableExists, $columnExists, (int)$headIdx);

        $parentDepth = 0;
        if ($parentIdx !== null) {
            $parentRow = $loadOrgFolderByIdx($db, $tableExists, $columnExists, $orgFolderTable, $parentIdx);
            if ($parentRow === null) {
                ApiResponse::error('INVALID_PARAM', 'parent_idx is invalid', 422, ['parent_idx' => $parentIdx]);
                exit;
            }
            if ((int)($parentRow['head_idx'] ?? 0) !== (int)$headIdx) {
                ApiResponse::error('BUSINESS_RULE_VIOLATION', 'head_idx와 parent_idx 소속이 다릅니다', 409);
                exit;
            }
            $parentDepth = max(0, (int)($parentRow['depth'] ?? 0));
        }

        $depth = $parentDepth + 1;
        if ($depth > 3) {
            ApiResponse::error('BUSINESS_RULE_VIOLATION', '폴더는 최대 3단계까지만 생성할 수 있습니다', 409, [
                'head_idx' => (int)$headIdx,
                'parent_idx' => $parentIdx ?? 0,
                'depth' => $depth,
            ]);
            exit;
        }

        $sortWhere = ['head_idx = ?'];
        $sortParams = [(int)$headIdx];
        if ($columnExists($db, $orgFolderTable, 'parent_idx')) {
            if ($parentIdx === null) {
                $sortWhere[] = '(parent_idx IS NULL OR parent_idx = 0)';
            } else {
                $sortWhere[] = 'parent_idx = ?';
                $sortParams[] = $parentIdx;
            }
        }
        if ($columnExists($db, $orgFolderTable, 'is_deleted')) {
            $sortWhere[] = 'ISNULL(is_deleted,0)=0';
        }

        $sortStmt = $db->prepare('SELECT ISNULL(MAX(sort_order), 0) + 1 FROM ' . $orgFolderTable . ' WHERE ' . implode(' AND ', $sortWhere));
        $sortStmt->execute($sortParams);
        $nextSortOrder = max(1, (int)$sortStmt->fetchColumn());

        $fieldMap = [
            'head_idx' => (int)$headIdx,
            'parent_idx' => $parentIdx,
            'name' => $name,
            'depth' => $depth,
            'sort_order' => $nextSortOrder,
        ];
        if ($columnExists($db, $orgFolderTable, 'is_deleted')) { $fieldMap['is_deleted'] = 0; }
        if ($columnExists($db, $orgFolderTable, 'created_at')) { $fieldMap['created_at'] = date('Y-m-d H:i:s'); }
        if ($columnExists($db, $orgFolderTable, 'created_by')) { $fieldMap['created_by'] = (int)($context['user_pk'] ?? 0); }
        if ($columnExists($db, $orgFolderTable, 'updated_at')) { $fieldMap['updated_at'] = date('Y-m-d H:i:s'); }
        if ($columnExists($db, $orgFolderTable, 'updated_by')) { $fieldMap['updated_by'] = (int)($context['user_pk'] ?? 0); }
        if ($columnExists($db, $orgFolderTable, 'regdate')) { $fieldMap['regdate'] = date('Y-m-d H:i:s'); }
        if ($columnExists($db, $orgFolderTable, 'registered_date')) { $fieldMap['registered_date'] = date('Y-m-d H:i:s'); }

        $columns = [];
        $values = [];
        foreach ($fieldMap as $column => $value) {
            if (!$columnExists($db, $orgFolderTable, $column)) {
                continue;
            }
            $columns[] = $column;
            $values[] = $value;
        }
        if ($columns === []) {
            ApiResponse::error('SCHEMA_ERROR', 'org folder insert columns are not available', 500);
            exit;
        }

        try {
            $db->beginTransaction();
            $ph = implode(', ', array_fill(0, count($columns), '?'));
            $stmt = $db->prepare('INSERT INTO ' . $orgFolderTable . ' (' . implode(', ', $columns) . ') VALUES (' . $ph . ')');
            $stmt->execute($values);

            $idStmt = $db->query('SELECT CAST(SCOPE_IDENTITY() AS INT) AS idx');
            $newIdx = (int)($idStmt ? $idStmt->fetchColumn() : 0);
            if ($newIdx <= 0) {
                throw new RuntimeException('failed to resolve inserted org folder idx');
            }
            $db->commit();

            $row = $loadOrgFolderByIdx($db, $tableExists, $columnExists, $orgFolderTable, $newIdx);
            $writeSvcAuditLog($db, $tableExists, $columnExists, 'HeadOffice.php', 'org_folder_insert', [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'target_table' => $orgFolderTable,
                'target_idx' => $newIdx,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'status' => 'SUCCESS',
                'message' => 'head org folder inserted',
                'head_idx' => (int)$headIdx,
                'parent_idx' => $parentIdx ?? 0,
                'depth' => $depth,
                'sort_order' => $nextSortOrder,
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'org_folder_insert', $orgFolderTable, $newIdx, [
                'head_idx' => (int)$headIdx,
                'parent_idx' => $parentIdx ?? 0,
                'depth' => $depth,
            ]);

            ApiResponse::success([
                'idx' => $newIdx,
                'item' => $row,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '조직도 폴더 등록 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'org_folder_update') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 2, 'current' => $roleLevel]);
            exit;
        }
        if (!$tableExists($db, $orgFolderTable)) {
            ApiResponse::error('SCHEMA_NOT_READY', $orgFolderTable . ' table is missing', 503);
            exit;
        }

        $idx = (int)($_POST['idx'] ?? 0);
        $name = FmsInputValidator::string($_POST, 'name', 120, true);
        if ($idx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'idx is required', 422);
            exit;
        }

        $current = $loadOrgFolderByIdx($db, $tableExists, $columnExists, $orgFolderTable, $idx);
        if ($current === null) {
            ApiResponse::error('NOT_FOUND', '폴더를 찾을 수 없습니다', 404);
            exit;
        }

        $setSql = ['name = ?'];
        $params = [$name];
        if ($columnExists($db, $orgFolderTable, 'updated_at')) {
            $setSql[] = 'updated_at = ?';
            $params[] = date('Y-m-d H:i:s');
        }
        if ($columnExists($db, $orgFolderTable, 'updated_by')) {
            $setSql[] = 'updated_by = ?';
            $params[] = (int)($context['user_pk'] ?? 0);
        }
        $params[] = $idx;

        $stmt = $db->prepare('UPDATE ' . $orgFolderTable . ' SET ' . implode(', ', $setSql) . ' WHERE idx = ?');
        $stmt->execute($params);

        $row = $loadOrgFolderByIdx($db, $tableExists, $columnExists, $orgFolderTable, $idx);
        $writeSvcAuditLog($db, $tableExists, $columnExists, 'HeadOffice.php', 'org_folder_update', [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'target_table' => $orgFolderTable,
            'target_idx' => $idx,
            'actor_user_pk' => (int)($context['user_pk'] ?? 0),
            'actor_login_id' => (string)($context['login_id'] ?? ''),
            'status' => 'SUCCESS',
            'message' => 'head org folder updated',
            'name' => $name,
        ]);
        $shadowId = $enqueueShadow($db, $security, $context, 'org_folder_update', $orgFolderTable, $idx, ['name' => $name]);

        ApiResponse::success([
            'idx' => $idx,
            'item' => $row,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '조직도 폴더 수정 성공');
        exit;
    }

    if ($todo === 'org_folder_delete') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 2, 'current' => $roleLevel]);
            exit;
        }
        if (!$tableExists($db, $orgFolderTable)) {
            ApiResponse::error('SCHEMA_NOT_READY', $orgFolderTable . ' table is missing', 503);
            exit;
        }

        $idx = (int)($_POST['idx'] ?? 0);
        if ($idx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'idx is required', 422);
            exit;
        }

        $current = $loadOrgFolderByIdx($db, $tableExists, $columnExists, $orgFolderTable, $idx);
        if ($current === null) {
            ApiResponse::error('NOT_FOUND', '폴더를 찾을 수 없습니다', 404);
            exit;
        }

        $childWhere = ['parent_idx = ?'];
        $childParams = [$idx];
        if ($columnExists($db, $orgFolderTable, 'is_deleted')) {
            $childWhere[] = 'ISNULL(is_deleted,0)=0';
        }
        $childStmt = $db->prepare('SELECT COUNT(*) FROM ' . $orgFolderTable . ' WHERE ' . implode(' AND ', $childWhere));
        $childStmt->execute($childParams);
        if ((int)$childStmt->fetchColumn() > 0) {
            ApiResponse::error('BUSINESS_RULE_VIOLATION', '하위 폴더가 존재하여 삭제할 수 없습니다', 409);
            exit;
        }

        try {
            $db->beginTransaction();

            $deleteMode = 'hard';
            $affected = 0;

            if ($columnExists($db, $orgFolderTable, 'is_deleted')) {
                $deleteMode = 'soft';
                $setSql = ['is_deleted = 1'];
                $params = [];
                if ($columnExists($db, $orgFolderTable, 'deleted_at')) { $setSql[] = 'deleted_at = GETDATE()'; }
                if ($columnExists($db, $orgFolderTable, 'deleted_by')) { $setSql[] = 'deleted_by = ?'; $params[] = (int)($context['user_pk'] ?? 0); }
                if ($columnExists($db, $orgFolderTable, 'updated_at')) { $setSql[] = 'updated_at = GETDATE()'; }
                if ($columnExists($db, $orgFolderTable, 'updated_by')) { $setSql[] = 'updated_by = ?'; $params[] = (int)($context['user_pk'] ?? 0); }
                $params[] = $idx;

                $stmt = $db->prepare('UPDATE ' . $orgFolderTable . ' SET ' . implode(', ', $setSql) . ' WHERE idx = ?');
                $stmt->execute($params);
                $affected = (int)$stmt->rowCount();
            } else {
                if ($tableExists($db, 'Tb_Members') && $columnExists($db, 'Tb_Members', 'org_folder_idx')) {
                    $stmt = $db->prepare('UPDATE Tb_Members SET org_folder_idx = NULL WHERE org_folder_idx = ?');
                    $stmt->execute([$idx]);
                }
                if ($tableExists($db, 'Tb_PhoneBook') && $columnExists($db, 'Tb_PhoneBook', 'branch_folder_idx')) {
                    $stmt = $db->prepare('UPDATE Tb_PhoneBook SET branch_folder_idx = NULL WHERE branch_folder_idx = ?');
                    $stmt->execute([$idx]);
                }
                $stmt = $db->prepare('DELETE FROM ' . $orgFolderTable . ' WHERE idx = ?');
                $stmt->execute([$idx]);
                $affected = (int)$stmt->rowCount();
            }

            $db->commit();

            $writeSvcAuditLog($db, $tableExists, $columnExists, 'HeadOffice.php', 'org_folder_delete', [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'target_table' => $orgFolderTable,
                'target_idx' => $idx,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'status' => 'SUCCESS',
                'message' => 'head org folder deleted',
                'delete_mode' => $deleteMode,
                'affected' => $affected,
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'org_folder_delete', $orgFolderTable, $idx, [
                'delete_mode' => $deleteMode,
            ]);

            ApiResponse::success([
                'idx' => $idx,
                'deleted_count' => $affected,
                'delete_mode' => $deleteMode,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '조직도 폴더 삭제 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'org_folder_reorder') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 2, 'current' => $roleLevel]);
            exit;
        }
        if (!$tableExists($db, $orgFolderTable)) {
            ApiResponse::error('SCHEMA_NOT_READY', $orgFolderTable . ' table is missing', 503);
            exit;
        }

        $orderRaw = $_POST['order'] ?? null;
        if ($orderRaw === null || $orderRaw === '') {
            ApiResponse::error('INVALID_PARAM', 'order is required', 422);
            exit;
        }

        $items = [];
        if (is_array($orderRaw)) {
            $items = $orderRaw;
        } else {
            $decoded = json_decode((string)$orderRaw, true);
            if (is_array($decoded)) {
                $items = $decoded;
            }
        }
        if ($items === []) {
            ApiResponse::error('INVALID_PARAM', 'order must be a JSON array', 422);
            exit;
        }

        $orderedIdxList = [];
        foreach ($items as $item) {
            $candidate = null;
            if (is_array($item)) {
                $candidate = $item['idx'] ?? $item['folder_idx'] ?? null;
            } else {
                $candidate = $item;
            }

            $token = trim((string)$candidate);
            if ($token === '' || !ctype_digit($token)) {
                continue;
            }
            $value = (int)$token;
            if ($value > 0) {
                $orderedIdxList[] = $value;
            }
        }
        $orderedIdxList = array_values(array_unique($orderedIdxList));
        if ($orderedIdxList === []) {
            ApiResponse::error('INVALID_PARAM', 'order contains no valid idx', 422);
            exit;
        }

        $ph = implode(',', array_fill(0, count($orderedIdxList), '?'));
        $where = "idx IN ({$ph})";
        if ($columnExists($db, $orgFolderTable, 'is_deleted')) {
            $where .= ' AND ISNULL(is_deleted,0)=0';
        }
        $stmt = $db->prepare('SELECT idx, head_idx, ISNULL(parent_idx,0) AS parent_idx FROM ' . $orgFolderTable . ' WHERE ' . $where);
        $stmt->execute($orderedIdxList);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rows = is_array($rows) ? $rows : [];
        if (count($rows) !== count($orderedIdxList)) {
            ApiResponse::error('INVALID_PARAM', 'order contains invalid idx', 422);
            exit;
        }

        $headSet = [];
        $parentSet = [];
        foreach ($rows as $row) {
            $headSet[(int)($row['head_idx'] ?? 0)] = true;
            $parentSet[(int)($row['parent_idx'] ?? 0)] = true;
        }
        if (count($headSet) !== 1 || count($parentSet) !== 1) {
            ApiResponse::error('BUSINESS_RULE_VIOLATION', '같은 본사/같은 부모 폴더 내에서만 정렬할 수 있습니다', 409);
            exit;
        }
        $headIdx = (int)array_key_first($headSet);
        $parentIdx = (int)array_key_first($parentSet);

        try {
            $db->beginTransaction();
            $updated = 0;
            $sort = 1;
            foreach ($orderedIdxList as $folderIdx) {
                $setSql = ['sort_order = ?'];
                $params = [$sort];
                if ($columnExists($db, $orgFolderTable, 'updated_at')) {
                    $setSql[] = 'updated_at = ?';
                    $params[] = date('Y-m-d H:i:s');
                }
                if ($columnExists($db, $orgFolderTable, 'updated_by')) {
                    $setSql[] = 'updated_by = ?';
                    $params[] = (int)($context['user_pk'] ?? 0);
                }
                $params[] = $folderIdx;
                $uStmt = $db->prepare('UPDATE ' . $orgFolderTable . ' SET ' . implode(', ', $setSql) . ' WHERE idx = ?');
                $uStmt->execute($params);
                $updated += (int)$uStmt->rowCount();
                $sort++;
            }
            $db->commit();

            $writeSvcAuditLog($db, $tableExists, $columnExists, 'HeadOffice.php', 'org_folder_reorder', [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'target_table' => $orgFolderTable,
                'target_idx' => $orderedIdxList[0] ?? 0,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'status' => 'SUCCESS',
                'message' => 'head org folder reordered',
                'head_idx' => $headIdx,
                'parent_idx' => $parentIdx,
                'idx_list' => $orderedIdxList,
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'org_folder_reorder', $orgFolderTable, $orderedIdxList[0] ?? 0, [
                'head_idx' => $headIdx,
                'parent_idx' => $parentIdx,
                'idx_list' => $orderedIdxList,
            ]);

            ApiResponse::success([
                'head_idx' => $headIdx,
                'parent_idx' => $parentIdx,
                'idx_list' => $orderedIdxList,
                'updated_count' => $updated,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '조직도 폴더 정렬 변경 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'assign_branch_folder') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 2, 'current' => $roleLevel]);
            exit;
        }
        if (!$tableExists($db, 'Tb_Members')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Members table is missing', 503);
            exit;
        }
        if (!$columnExists($db, 'Tb_Members', 'org_folder_idx')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Members.org_folder_idx column is missing', 503);
            exit;
        }

        $memberIdx = FmsInputValidator::int($_POST, 'member_idx', true, 1);
        $folderIdx = FmsInputValidator::int($_POST, 'folder_idx', false, 0);
        if ($folderIdx === null) {
            ApiResponse::error('INVALID_PARAM', 'folder_idx is required', 422);
            exit;
        }
        $folderIdx = max(0, (int)$folderIdx);

        $memberSelect = 'idx';
        if ($columnExists($db, 'Tb_Members', 'head_idx')) {
            $memberSelect .= ', head_idx';
        }
        $memberWhere = ['idx = ?'];
        if ($columnExists($db, 'Tb_Members', 'is_deleted')) {
            $memberWhere[] = 'ISNULL(is_deleted,0)=0';
        }
        $memberStmt = $db->prepare('SELECT ' . $memberSelect . ' FROM Tb_Members WHERE ' . implode(' AND ', $memberWhere));
        $memberStmt->execute([(int)$memberIdx]);
        $memberRow = $memberStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($memberRow)) {
            ApiResponse::error('NOT_FOUND', '사업장을 찾을 수 없습니다', 404);
            exit;
        }

        $folderHeadIdx = 0;
        if ($folderIdx > 0) {
            if (!$tableExists($db, $orgFolderTable)) {
                ApiResponse::error('SCHEMA_NOT_READY', $orgFolderTable . ' table is missing', 503);
                exit;
            }
            $folderRow = $loadOrgFolderByIdx($db, $tableExists, $columnExists, $orgFolderTable, $folderIdx);
            if ($folderRow === null) {
                ApiResponse::error('INVALID_PARAM', 'folder_idx is invalid', 422, ['folder_idx' => $folderIdx]);
                exit;
            }
            $folderHeadIdx = (int)($folderRow['head_idx'] ?? 0);
            $memberHeadIdx = (int)($memberRow['head_idx'] ?? 0);
            if ($memberHeadIdx > 0 && $folderHeadIdx > 0 && $memberHeadIdx !== $folderHeadIdx) {
                ApiResponse::error('BUSINESS_RULE_VIOLATION', '사업장 본사와 폴더 본사가 일치하지 않습니다', 409, [
                    'member_head_idx' => $memberHeadIdx,
                    'folder_head_idx' => $folderHeadIdx,
                ]);
                exit;
            }
        }

        $setSql = ['org_folder_idx = ?'];
        $params = [$folderIdx > 0 ? $folderIdx : null];
        if ($columnExists($db, 'Tb_Members', 'updated_at')) {
            $setSql[] = 'updated_at = ?';
            $params[] = date('Y-m-d H:i:s');
        }
        if ($columnExists($db, 'Tb_Members', 'updated_by')) {
            $setSql[] = 'updated_by = ?';
            $params[] = (int)($context['user_pk'] ?? 0);
        }
        $params[] = (int)$memberIdx;

        $where = ['idx = ?'];
        if ($columnExists($db, 'Tb_Members', 'is_deleted')) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }
        $stmt = $db->prepare('UPDATE Tb_Members SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);

        $row = $loadMember($db, $tableExists, $columnExists, (int)$memberIdx);
        $writeSvcAuditLog($db, $tableExists, $columnExists, 'HeadOffice.php', 'assign_branch_folder', [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'target_table' => 'Tb_Members',
            'target_idx' => (int)$memberIdx,
            'actor_user_pk' => (int)($context['user_pk'] ?? 0),
            'actor_login_id' => (string)($context['login_id'] ?? ''),
            'status' => 'SUCCESS',
            'message' => 'branch folder assigned',
            'folder_idx' => $folderIdx,
            'folder_head_idx' => $folderHeadIdx,
        ]);
        $shadowId = $enqueueShadow($db, $security, $context, 'assign_branch_folder', 'Tb_Members', (int)$memberIdx, [
            'member_idx' => (int)$memberIdx,
            'folder_idx' => $folderIdx,
        ]);

        ApiResponse::success([
            'member_idx' => (int)$memberIdx,
            'folder_idx' => $folderIdx,
            'item' => $row,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '사업장 폴더 지정 성공');
        exit;
    }

    if ($todo === 'reorder_branches') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 2, 'current' => $roleLevel]);
            exit;
        }
        if (!$tableExists($db, 'Tb_Members')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Members table is missing', 503);
            exit;
        }
        if (!$columnExists($db, 'Tb_Members', 'head_idx')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Members.head_idx column is missing', 503);
            exit;
        }
        if (!$columnExists($db, 'Tb_Members', 'field1')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Members.field1 column is missing', 503);
            exit;
        }

        $headIdx = FmsInputValidator::int($_POST, 'head_idx', true, 1);
        $assertHeadExists($db, $tableExists, $columnExists, (int)$headIdx);

        $orderRaw = $_POST['order'] ?? null;
        if ($orderRaw === null || $orderRaw === '') {
            ApiResponse::error('INVALID_PARAM', 'order is required', 422);
            exit;
        }

        $items = [];
        if (is_array($orderRaw)) {
            $items = $orderRaw;
        } else {
            $decoded = json_decode((string)$orderRaw, true);
            if (is_array($decoded)) {
                $items = $decoded;
            }
        }
        if ($items === []) {
            ApiResponse::error('INVALID_PARAM', 'order must be a JSON array', 422);
            exit;
        }

        $memberOrder = [];
        foreach ($items as $item) {
            $candidate = null;
            if (is_array($item)) {
                $candidate = $item['member_idx'] ?? $item['idx'] ?? null;
            } else {
                $candidate = $item;
            }
            $token = trim((string)$candidate);
            if ($token !== '' && ctype_digit($token)) {
                $value = (int)$token;
                if ($value > 0) {
                    $memberOrder[] = $value;
                }
            }
        }
        $memberOrder = array_values(array_unique($memberOrder));
        if ($memberOrder === []) {
            ApiResponse::error('INVALID_PARAM', 'order contains no valid member_idx', 422);
            exit;
        }

        $ph = implode(',', array_fill(0, count($memberOrder), '?'));
        $where = 'head_idx = ? AND idx IN (' . $ph . ')';
        if ($columnExists($db, 'Tb_Members', 'is_deleted')) {
            $where .= ' AND ISNULL(is_deleted,0)=0';
        }
        $checkStmt = $db->prepare('SELECT idx FROM Tb_Members WHERE ' . $where);
        $checkStmt->execute(array_merge([(int)$headIdx], $memberOrder));
        $rows = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || count($rows) !== count($memberOrder)) {
            ApiResponse::error('INVALID_PARAM', 'order contains member not linked to head_idx', 422, [
                'head_idx' => (int)$headIdx,
            ]);
            exit;
        }

        try {
            $db->beginTransaction();
            $updated = 0;
            $sort = 1;
            foreach ($memberOrder as $memberIdx) {
                $setSql = ['field1 = ?'];
                $params = [$sort];
                if ($columnExists($db, 'Tb_Members', 'updated_at')) {
                    $setSql[] = 'updated_at = ?';
                    $params[] = date('Y-m-d H:i:s');
                }
                if ($columnExists($db, 'Tb_Members', 'updated_by')) {
                    $setSql[] = 'updated_by = ?';
                    $params[] = (int)($context['user_pk'] ?? 0);
                }

                $memberWhere = ['idx = ?', 'head_idx = ?'];
                if ($columnExists($db, 'Tb_Members', 'is_deleted')) {
                    $memberWhere[] = 'ISNULL(is_deleted,0)=0';
                }
                $params[] = $memberIdx;
                $params[] = (int)$headIdx;

                $stmt = $db->prepare('UPDATE Tb_Members SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $memberWhere));
                $stmt->execute($params);
                $updated += (int)$stmt->rowCount();
                $sort++;
            }
            $db->commit();

            $writeSvcAuditLog($db, $tableExists, $columnExists, 'HeadOffice.php', 'reorder_branches', [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'target_table' => 'Tb_Members',
                'target_idx' => $memberOrder[0] ?? 0,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'status' => 'SUCCESS',
                'message' => 'member order updated',
                'head_idx' => (int)$headIdx,
                'idx_list' => $memberOrder,
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'reorder_branches', 'Tb_Members', $memberOrder[0] ?? 0, [
                'head_idx' => (int)$headIdx,
                'idx_list' => $memberOrder,
            ]);

            ApiResponse::success([
                'head_idx' => (int)$headIdx,
                'idx_list' => $memberOrder,
                'updated_count' => $updated,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '사업장 순서 변경 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'update_settings') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 2, 'current' => $roleLevel]);
            exit;
        }

        $idx = (int)($_POST['idx'] ?? 0);
        if ($idx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'idx is required', 422);
            exit;
        }

        $existing = $loadHeadOfficeByIdx($db, $tableExists, $columnExists, $loadHeadOfficeAttachments, $idx);
        if ($existing === null) {
            ApiResponse::error('NOT_FOUND', '본사를 찾을 수 없습니다', 404);
            exit;
        }

        $normalizeOptionValue = static function (mixed $raw): string {
            if (is_array($raw)) {
                $items = [];
                foreach ($raw as $item) {
                    $value = trim((string)$item);
                    if ($value !== '') {
                        $items[] = $value;
                    }
                }
                $items = array_values(array_unique($items));
                return implode(',', $items);
            }
            return trim((string)$raw);
        };

        $allowed = [
            'head_structure' => ['max' => 30, 'normalize' => 'string'],
            'head_type' => ['max' => 30, 'normalize' => 'string'],
            'contract_type' => ['max' => 50, 'normalize' => 'string'],
            'main_business' => ['max' => 120, 'normalize' => 'string'],
            'grade_options' => ['max' => 2000, 'normalize' => 'options'],
            'title_options' => ['max' => 2000, 'normalize' => 'options'],
            'memo' => ['max' => 2000, 'normalize' => 'string'],
        ];

        $setSql = [];
        $params = [];
        $hasSettingsField = false;
        foreach ($allowed as $field => $meta) {
            if (!array_key_exists($field, $_POST) || !$columnExists($db, 'Tb_HeadOffice', $field)) {
                continue;
            }

            $value = $meta['normalize'] === 'options'
                ? $normalizeOptionValue($_POST[$field])
                : trim((string)$_POST[$field]);
            $value = FmsInputValidator::string(['v' => $value], 'v', (int)$meta['max'], false);

            $setSql[] = $field . ' = ?';
            $params[] = $value;
            $hasSettingsField = true;
        }

        if ($columnExists($db, 'Tb_HeadOffice', 'updated_at')) {
            $setSql[] = 'updated_at = ?';
            $params[] = date('Y-m-d H:i:s');
        }
        if ($columnExists($db, 'Tb_HeadOffice', 'updated_by')) {
            $setSql[] = 'updated_by = ?';
            $params[] = (int)($context['user_pk'] ?? 0);
        }

        if (!$hasSettingsField) {
            ApiResponse::error('INVALID_PARAM', 'no settings field provided', 422);
            exit;
        }

        $where = ['idx = ?'];
        if ($hasSoftDelete) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }
        $params[] = $idx;

        $stmt = $db->prepare('UPDATE Tb_HeadOffice SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);

        $item = $loadHeadOfficeByIdx($db, $tableExists, $columnExists, $loadHeadOfficeAttachments, $idx);
        $writeSvcAuditLog($db, $tableExists, $columnExists, 'HeadOffice.php', 'update_settings', [
            'service_code' => $serviceCode,
            'tenant_id' => $tenantId,
            'target_table' => 'Tb_HeadOffice',
            'target_idx' => $idx,
            'actor_user_pk' => (int)($context['user_pk'] ?? 0),
            'actor_login_id' => (string)($context['login_id'] ?? ''),
            'status' => 'SUCCESS',
            'message' => 'head office settings updated',
        ]);
        $shadowId = $enqueueShadow($db, $security, $context, 'update_settings', 'Tb_HeadOffice', $idx, ['head_idx' => $idx]);

        ApiResponse::success([
            'idx' => $idx,
            'item' => $item,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '본사 설정 저장 성공');
        exit;
    }

    if ($todo === 'create_head_from_member') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 2, 'current' => $roleLevel]);
            exit;
        }
        if (!$tableExists($db, 'Tb_Members')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Members table is missing', 503);
            exit;
        }
        if (!$columnExists($db, 'Tb_Members', 'head_idx')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Members.head_idx column is missing', 503);
            exit;
        }

        $memberIdx = FmsInputValidator::int($_POST, 'member_idx', true, 1);
        $memberNameCol = $firstExistingColumn($db, $columnExists, 'Tb_Members', ['name', 'member_name', 'branch_name']);
        $memberCardCol = $firstExistingColumn($db, $columnExists, 'Tb_Members', ['card_number', 'biz_number', 'business_number']);
        if ($memberNameCol === null) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_Members name column is missing', 503);
            exit;
        }

        $selectCols = [
            'idx',
            $memberNameCol . ' AS member_name',
        ];
        if ($memberCardCol !== null) { $selectCols[] = $memberCardCol . ' AS member_card_number'; }
        foreach (['ceo', 'tel', 'email', 'address', 'address_detail', 'zipcode', 'cooperation_contract', 'memo', 'head_idx'] as $column) {
            if ($columnExists($db, 'Tb_Members', $column)) {
                $selectCols[] = $column;
            }
        }

        $memberWhere = ['idx = ?'];
        if ($columnExists($db, 'Tb_Members', 'is_deleted')) {
            $memberWhere[] = 'ISNULL(is_deleted,0)=0';
        }
        $memberStmt = $db->prepare('SELECT ' . implode(', ', $selectCols) . ' FROM Tb_Members WHERE ' . implode(' AND ', $memberWhere));
        $memberStmt->execute([(int)$memberIdx]);
        $memberRow = $memberStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($memberRow)) {
            ApiResponse::error('NOT_FOUND', '사업장을 찾을 수 없습니다', 404);
            exit;
        }

        $linkedHeadIdx = (int)($memberRow['head_idx'] ?? 0);
        if ($linkedHeadIdx > 0) {
            $linkedHead = $loadHeadOfficeByIdx($db, $tableExists, $columnExists, $loadHeadOfficeAttachments, $linkedHeadIdx);
            if ($linkedHead !== null) {
                ApiResponse::error('BUSINESS_RULE_VIOLATION', '이미 본사에 연결된 사업장입니다', 409, [
                    'member_idx' => (int)$memberIdx,
                    'head_idx' => $linkedHeadIdx,
                    'head_name' => (string)($linkedHead['name'] ?? ''),
                ]);
                exit;
            }
        }

        $name = trim((string)($memberRow['member_name'] ?? ''));
        if ($name === '') {
            ApiResponse::error('INVALID_PARAM', '사업장명이 비어 있어 본사 생성이 불가합니다', 422);
            exit;
        }

        $cardNumber = trim((string)($memberRow['member_card_number'] ?? ''));
        if ($cardNumber !== '') {
            try {
                $cardNumber = FmsInputValidator::bizNumber($cardNumber, 'card_number', true);
            } catch (Throwable) {
                $cardNumber = '';
            }
        }
        if ($cardNumber !== '') {
            $dup = $findByCardNumber($db, $columnExists, $cardNumber);
            if ($dup !== null) {
                ApiResponse::error('DUPLICATE_CARD_NUMBER', '동일 사업자번호의 본사가 이미 존재합니다', 409, ['existing' => $dup]);
                exit;
            }
        }

        $headNumber = $nextHeadNumber($db, $columnExists);
        $memoText = trim((string)($memberRow['memo'] ?? ''));
        $autoMemo = 'auto created from member_idx=' . (int)$memberIdx;
        $mergedMemo = $memoText === '' ? $autoMemo : ($memoText . "\n" . $autoMemo);
        if (mb_strlen($mergedMemo) > 2000) {
            $mergedMemo = mb_substr($mergedMemo, 0, 2000);
        }

        $fieldMap = [
            'name' => $name,
            'head_number' => $headNumber,
            'ceo' => trim((string)($memberRow['ceo'] ?? '')),
            'card_number' => $cardNumber,
            'tel' => trim((string)($memberRow['tel'] ?? '')),
            'email' => trim((string)($memberRow['email'] ?? '')),
            'address' => trim((string)($memberRow['address'] ?? '')),
            'address_detail' => trim((string)($memberRow['address_detail'] ?? '')),
            'zipcode' => trim((string)($memberRow['zipcode'] ?? '')),
            'cooperation_contract' => trim((string)($memberRow['cooperation_contract'] ?? '')),
            'memo' => $mergedMemo,
        ];

        $columns = [];
        $values = [];
        foreach ($fieldMap as $column => $value) {
            if (!$columnExists($db, 'Tb_HeadOffice', $column)) {
                continue;
            }
            if (in_array($column, ['name', 'head_number'], true) || $value !== '') {
                $columns[] = $column;
                $values[] = $value;
            }
        }
        if ($columnExists($db, 'Tb_HeadOffice', 'is_deleted')) {
            $columns[] = 'is_deleted';
            $values[] = 0;
        }
        if ($columnExists($db, 'Tb_HeadOffice', 'registered_date')) {
            $columns[] = 'registered_date';
            $values[] = date('Y-m-d H:i:s');
        }
        if ($columnExists($db, 'Tb_HeadOffice', 'regdate')) {
            $columns[] = 'regdate';
            $values[] = date('Y-m-d H:i:s');
        }
        if ($columnExists($db, 'Tb_HeadOffice', 'created_by')) {
            $columns[] = 'created_by';
            $values[] = (int)($context['user_pk'] ?? 0);
        }
        if ($columnExists($db, 'Tb_HeadOffice', 'created_at')) {
            $columns[] = 'created_at';
            $values[] = date('Y-m-d H:i:s');
        }

        if ($columns === []) {
            ApiResponse::error('SCHEMA_ERROR', 'Tb_HeadOffice insertable columns not found', 500);
            exit;
        }

        try {
            $db->beginTransaction();

            $ph = implode(', ', array_fill(0, count($columns), '?'));
            $insertStmt = $db->prepare('INSERT INTO Tb_HeadOffice (' . implode(', ', $columns) . ') VALUES (' . $ph . ')');
            $insertStmt->execute($values);

            $idStmt = $db->query('SELECT CAST(SCOPE_IDENTITY() AS INT) AS idx');
            $newHeadIdx = (int)($idStmt ? $idStmt->fetchColumn() : 0);
            if ($newHeadIdx <= 0) {
                throw new RuntimeException('failed to resolve inserted head idx');
            }

            $memberSetSql = ['head_idx = ?'];
            $memberParams = [$newHeadIdx];
            if ($columnExists($db, 'Tb_Members', 'updated_at')) {
                $memberSetSql[] = 'updated_at = ?';
                $memberParams[] = date('Y-m-d H:i:s');
            }
            if ($columnExists($db, 'Tb_Members', 'updated_by')) {
                $memberSetSql[] = 'updated_by = ?';
                $memberParams[] = (int)($context['user_pk'] ?? 0);
            }
            $memberParams[] = (int)$memberIdx;

            $memberUpdateWhere = ['idx = ?'];
            if ($columnExists($db, 'Tb_Members', 'is_deleted')) {
                $memberUpdateWhere[] = 'ISNULL(is_deleted,0)=0';
            }
            $memberUpdateStmt = $db->prepare('UPDATE Tb_Members SET ' . implode(', ', $memberSetSql) . ' WHERE ' . implode(' AND ', $memberUpdateWhere));
            $memberUpdateStmt->execute($memberParams);

            $db->commit();

            $headItem = $loadHeadOfficeByIdx($db, $tableExists, $columnExists, $loadHeadOfficeAttachments, $newHeadIdx);

            $writeSvcAuditLog($db, $tableExists, $columnExists, 'HeadOffice.php', 'create_head_from_member', [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'target_table' => 'Tb_HeadOffice',
                'target_idx' => $newHeadIdx,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'status' => 'SUCCESS',
                'message' => 'head office created from member',
                'member_idx' => (int)$memberIdx,
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'create_head_from_member', 'Tb_HeadOffice', $newHeadIdx, [
                'head_idx' => $newHeadIdx,
                'member_idx' => (int)$memberIdx,
            ]);

            ApiResponse::success([
                'head_idx' => $newHeadIdx,
                'member_idx' => (int)$memberIdx,
                'item' => $headItem,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '사업장 기반 본사 생성 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'insert') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 2, 'current' => $roleLevel]);
            exit;
        }

        $name = FmsInputValidator::string($_POST, 'name', 120, true);
        $ceo = FmsInputValidator::string($_POST, 'ceo', 80, false);
        $cardNumber = FmsInputValidator::bizNumber((string)($_POST['card_number'] ?? ''), 'card_number', true);
        $tel = FmsInputValidator::phone((string)($_POST['tel'] ?? ''), 'tel', true);
        $email = FmsInputValidator::email((string)($_POST['email'] ?? ''), 'email', true);

        if ($cardNumber !== '') {
            $dup = $findByCardNumber($db, $columnExists, $cardNumber);
            if ($dup !== null) {
                ApiResponse::error('DUPLICATE_CARD_NUMBER', '이미 등록된 사업자번호입니다', 409, ['existing' => $dup]);
                exit;
            }
        }

        $headNumber = trim((string)($_POST['head_number'] ?? ''));
        if ($headNumber === '') {
            $headNumber = $nextHeadNumber($db, $columnExists);
        }

        $employeeIdx = (int)($_POST['employee_idx'] ?? 0);
        $employeeName = trim((string)($_POST['employee_name'] ?? ''));
        $employeeIdx = $resolveEmployeeIdx($db, $tableExists, $columnExists, $employeeIdx, $employeeName);

        $fieldMap = [
            'name' => $name,
            'head_number' => $headNumber,
            'ceo' => $ceo,
            'card_number' => $cardNumber,
            'tel' => $tel,
            'email' => $email,
            'address' => FmsInputValidator::string($_POST, 'address', 255, false),
            'address_detail' => FmsInputValidator::string($_POST, 'address_detail', 255, false),
            'zipcode' => FmsInputValidator::string($_POST, 'zipcode', 20, false),
            'head_type' => FmsInputValidator::string($_POST, 'head_type', 30, false),
            'head_structure' => FmsInputValidator::string($_POST, 'head_structure', 30, false),
            'cooperation_contract' => FmsInputValidator::string($_POST, 'cooperation_contract', 50, false),
            'used_estimate' => FmsInputValidator::string($_POST, 'used_estimate', 500, false),
            'contract_type' => FmsInputValidator::string($_POST, 'contract_type', 50, false),
            'main_business' => FmsInputValidator::string($_POST, 'main_business', 120, false),
            'memo' => FmsInputValidator::string($_POST, 'memo', 2000, false),
        ];

        $columns = [];
        $values = [];
        foreach ($fieldMap as $column => $value) {
            if (!$columnExists($db, 'Tb_HeadOffice', $column)) {
                continue;
            }
            if ($column === 'name' || $column === 'head_number' || $value !== '') {
                $columns[] = $column;
                $values[] = $value;
            }
        }

        if ($columnExists($db, 'Tb_HeadOffice', 'employee_idx') && $employeeIdx > 0) {
            $columns[] = 'employee_idx';
            $values[] = $employeeIdx;
        }
        if ($columnExists($db, 'Tb_HeadOffice', 'registered_date')) {
            $columns[] = 'registered_date';
            $values[] = date('Y-m-d H:i:s');
        }
        if ($columnExists($db, 'Tb_HeadOffice', 'regdate')) {
            $columns[] = 'regdate';
            $values[] = date('Y-m-d H:i:s');
        }
        if ($columnExists($db, 'Tb_HeadOffice', 'is_deleted')) {
            $columns[] = 'is_deleted';
            $values[] = 0;
        }
        if ($columnExists($db, 'Tb_HeadOffice', 'created_by')) {
            $columns[] = 'created_by';
            $values[] = (int)($context['user_pk'] ?? 0);
        }
        if ($columnExists($db, 'Tb_HeadOffice', 'created_at')) {
            $columns[] = 'created_at';
            $values[] = date('Y-m-d H:i:s');
        }

        if ($columns === []) {
            ApiResponse::error('SCHEMA_ERROR', 'insertable columns not found', 500);
            exit;
        }

        try {
            $db->beginTransaction();
            $ph = implode(', ', array_fill(0, count($columns), '?'));
            $sql = 'INSERT INTO Tb_HeadOffice (' . implode(', ', $columns) . ') VALUES (' . $ph . ')';
            $stmt = $db->prepare($sql);
            $stmt->execute($values);

            $idStmt = $db->query('SELECT CAST(SCOPE_IDENTITY() AS INT) AS idx');
            $newIdx = (int)($idStmt ? $idStmt->fetchColumn() : 0);
            if ($newIdx <= 0) {
                throw new RuntimeException('failed to resolve inserted idx');
            }
            $db->commit();

            $attachSummary = $saveAttachments($db, $tableExists, $columnExists, $normalizeUploadFiles, $newIdx, $_FILES, (int)($context['user_pk'] ?? 0));
            $item = $loadHeadOfficeByIdx($db, $tableExists, $columnExists, $loadHeadOfficeAttachments, $newIdx);

            $writeSvcAuditLog($db, $tableExists, $columnExists, 'HeadOffice.php', 'insert', [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'target_table' => 'Tb_HeadOffice',
                'target_idx' => $newIdx,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'status' => 'SUCCESS',
                'message' => 'head office inserted',
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'insert', 'Tb_HeadOffice', $newIdx, ['head_idx' => $newIdx]);
            DevLogService::tryLog('FMS', 'HeadOffice insert', 'HeadOffice insert todo executed', 1);

            ApiResponse::success([
                'idx' => $newIdx,
                'item' => $item,
                'attachments' => $attachSummary,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '본사 등록 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'update') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 2, 'current' => $roleLevel]);
            exit;
        }

        $idx = (int)($_POST['idx'] ?? $_GET['idx'] ?? 0);
        if ($idx <= 0) {
            ApiResponse::error('INVALID_PARAM', 'idx is required', 422);
            exit;
        }

        $existing = $loadHeadOfficeByIdx($db, $tableExists, $columnExists, $loadHeadOfficeAttachments, $idx);
        if ($existing === null) {
            ApiResponse::error('NOT_FOUND', '본사를 찾을 수 없습니다', 404);
            exit;
        }

        $allowed = [
            'name' => ['type' => 'string', 'max' => 120],
            'head_number' => ['type' => 'string', 'max' => 30],
            'ceo' => ['type' => 'string', 'max' => 80],
            'card_number' => ['type' => 'biz'],
            'tel' => ['type' => 'phone'],
            'email' => ['type' => 'email'],
            'address' => ['type' => 'string', 'max' => 255],
            'address_detail' => ['type' => 'string', 'max' => 255],
            'zipcode' => ['type' => 'string', 'max' => 20],
            'head_type' => ['type' => 'string', 'max' => 30],
            'head_structure' => ['type' => 'string', 'max' => 30],
            'cooperation_contract' => ['type' => 'string', 'max' => 50],
            'used_estimate' => ['type' => 'string', 'max' => 500],
            'contract_type' => ['type' => 'string', 'max' => 50],
            'main_business' => ['type' => 'string', 'max' => 120],
            'memo' => ['type' => 'string', 'max' => 2000],
        ];

        $setSql = [];
        $params = [];

        foreach ($allowed as $field => $meta) {
            if (!array_key_exists($field, $_POST) || !$columnExists($db, 'Tb_HeadOffice', $field)) {
                continue;
            }

            $value = '';
            if ($meta['type'] === 'biz') {
                $value = FmsInputValidator::bizNumber((string)$_POST[$field], $field, true);
                if ($value !== '') {
                    $dup = $findByCardNumber($db, $columnExists, $value, $idx);
                    if ($dup !== null) {
                        ApiResponse::error('DUPLICATE_CARD_NUMBER', '이미 등록된 사업자번호입니다', 409, ['existing' => $dup]);
                        exit;
                    }
                }
            } elseif ($meta['type'] === 'phone') {
                $value = FmsInputValidator::phone((string)$_POST[$field], $field, true);
            } elseif ($meta['type'] === 'email') {
                $value = FmsInputValidator::email((string)$_POST[$field], $field, true);
            } else {
                $value = FmsInputValidator::string($_POST, $field, (int)$meta['max'], false);
            }

            if ($field === 'name' && $value === '') {
                ApiResponse::error('INVALID_PARAM', 'name cannot be empty', 422);
                exit;
            }

            $setSql[] = $field . ' = ?';
            $params[] = $value;
        }

        if ($columnExists($db, 'Tb_HeadOffice', 'employee_idx') && (array_key_exists('employee_idx', $_POST) || array_key_exists('employee_name', $_POST))) {
            $employeeIdx = (int)($_POST['employee_idx'] ?? 0);
            $employeeName = trim((string)($_POST['employee_name'] ?? ''));
            $resolved = $resolveEmployeeIdx($db, $tableExists, $columnExists, $employeeIdx, $employeeName);
            $setSql[] = 'employee_idx = ?';
            $params[] = $resolved > 0 ? $resolved : null;
        }

        if ($columnExists($db, 'Tb_HeadOffice', 'updated_at')) {
            $setSql[] = 'updated_at = ?';
            $params[] = date('Y-m-d H:i:s');
        }
        if ($columnExists($db, 'Tb_HeadOffice', 'updated_by')) {
            $setSql[] = 'updated_by = ?';
            $params[] = (int)($context['user_pk'] ?? 0);
        }

        if ($setSql === []) {
            ApiResponse::error('INVALID_PARAM', 'no updatable fields provided', 422);
            exit;
        }

        $where = ['idx = ?'];
        if ($hasSoftDelete) {
            $where[] = 'ISNULL(is_deleted,0)=0';
        }
        $params[] = $idx;

        try {
            $db->beginTransaction();
            $sql = 'UPDATE Tb_HeadOffice SET ' . implode(', ', $setSql) . ' WHERE ' . implode(' AND ', $where);
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $db->commit();

            $attachSummary = $saveAttachments($db, $tableExists, $columnExists, $normalizeUploadFiles, $idx, $_FILES, (int)($context['user_pk'] ?? 0));
            $item = $loadHeadOfficeByIdx($db, $tableExists, $columnExists, $loadHeadOfficeAttachments, $idx);

            $writeSvcAuditLog($db, $tableExists, $columnExists, 'HeadOffice.php', 'update', [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'target_table' => 'Tb_HeadOffice',
                'target_idx' => $idx,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'status' => 'SUCCESS',
                'message' => 'head office updated',
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'update', 'Tb_HeadOffice', $idx, ['head_idx' => $idx]);
            DevLogService::tryLog('FMS', 'HeadOffice update', 'HeadOffice update todo executed', 1);

            ApiResponse::success([
                'idx' => $idx,
                'item' => $item,
                'attachments' => $attachSummary,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '본사 수정 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'bulk_update') {
        if ($roleLevel < 1 || $roleLevel > 3) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 3, 'current' => $roleLevel]);
            exit;
        }

        $idxList = FmsInputValidator::idxList($_POST['idx_list'] ?? ($_POST['idx'] ?? ''));
        if ($idxList === []) {
            ApiResponse::error('INVALID_PARAM', 'idx_list is required', 422);
            exit;
        }

        $allowed = [
            'head_type', 'head_structure', 'cooperation_contract', 'contract_type',
            'main_business', 'memo', 'employee_idx', 'employee_name', 'used_estimate',
        ];

        $setSql = [];
        $params = [];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $_POST) || !$columnExists($db, 'Tb_HeadOffice', $field === 'employee_name' ? 'employee_idx' : $field)) {
                continue;
            }

            if ($field === 'employee_name') {
                continue;
            }

            if ($field === 'employee_idx') {
                $employeeIdx = (int)($_POST['employee_idx'] ?? 0);
                $employeeName = trim((string)($_POST['employee_name'] ?? ''));
                $resolved = $resolveEmployeeIdx($db, $tableExists, $columnExists, $employeeIdx, $employeeName);
                $setSql[] = 'employee_idx = ?';
                $params[] = $resolved > 0 ? $resolved : null;
                continue;
            }

            $value = trim((string)$_POST[$field]);
            $setSql[] = $field . ' = ?';
            $params[] = $value;
        }

        if ($setSql === []) {
            ApiResponse::error('INVALID_PARAM', 'no fields for bulk_update', 422);
            exit;
        }

        if ($columnExists($db, 'Tb_HeadOffice', 'updated_at')) {
            $setSql[] = 'updated_at = ?';
            $params[] = date('Y-m-d H:i:s');
        }
        if ($columnExists($db, 'Tb_HeadOffice', 'updated_by')) {
            $setSql[] = 'updated_by = ?';
            $params[] = (int)($context['user_pk'] ?? 0);
        }

        $ph = implode(',', array_fill(0, count($idxList), '?'));
        $where = "idx IN ({$ph})";
        if ($hasSoftDelete) {
            $where .= ' AND ISNULL(is_deleted,0)=0';
        }

        $params = array_merge($params, $idxList);

        try {
            $db->beginTransaction();
            $stmt = $db->prepare('UPDATE Tb_HeadOffice SET ' . implode(', ', $setSql) . ' WHERE ' . $where);
            $stmt->execute($params);
            $affected = (int)$stmt->rowCount();
            $db->commit();

            $writeSvcAuditLog($db, $tableExists, $columnExists, 'HeadOffice.php', 'bulk_update', [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'target_table' => 'Tb_HeadOffice',
                'target_idx' => $idxList[0] ?? 0,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'status' => 'SUCCESS',
                'message' => 'head office bulk updated',
                'idx_list' => $idxList,
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'bulk_update', 'Tb_HeadOffice', $idxList[0] ?? 0, ['idx_list' => $idxList]);

            ApiResponse::success([
                'idx_list' => $idxList,
                'requested_count' => count($idxList),
                'updated_count' => $affected,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '본사 일괄 수정 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    if ($todo === 'restore') {
        if ($roleLevel < 1 || $roleLevel > 4) {
            ApiResponse::error('FORBIDDEN', 'insufficient role level', 403, ['required' => 4, 'current' => $roleLevel]);
            exit;
        }
        if (!$hasSoftDelete) {
            ApiResponse::error('UNSUPPORTED', 'restore is not available on hard-delete schema', 409);
            exit;
        }

        $idxList = FmsInputValidator::idxList($_POST['idx_list'] ?? ($_POST['idx'] ?? ''));
        if ($idxList === []) {
            ApiResponse::error('INVALID_PARAM', 'idx_list is required', 422);
            exit;
        }

        $ph = implode(',', array_fill(0, count($idxList), '?'));
        $setSql = ['is_deleted = 0'];
        if ($columnExists($db, 'Tb_HeadOffice', 'deleted_at')) { $setSql[] = 'deleted_at = NULL'; }
        if ($columnExists($db, 'Tb_HeadOffice', 'deleted_by')) { $setSql[] = 'deleted_by = NULL'; }
        if ($columnExists($db, 'Tb_HeadOffice', 'updated_at')) { $setSql[] = 'updated_at = GETDATE()'; }

        $stmt = $db->prepare('UPDATE Tb_HeadOffice SET ' . implode(', ', $setSql) . " WHERE idx IN ({$ph})");
        $stmt->execute($idxList);
        $restored = (int)$stmt->rowCount();

        $shadowId = $enqueueShadow($db, $security, $context, 'restore', 'Tb_HeadOffice', $idxList[0] ?? 0, ['idx_list' => $idxList]);

        ApiResponse::success([
            'idx_list' => $idxList,
            'restored_count' => $restored,
            'shadow_queue_idx' => $shadowId,
        ], 'OK', '본사 복구 성공');
        exit;
    }

    if ($todo === 'delete_attach') {
        if ($roleLevel < 1 || $roleLevel > 2) {
            ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required' => 2, 'current' => $roleLevel]);
            exit;
        }
        if (!$tableExists($db, 'Tb_FileAttach')) {
            ApiResponse::error('SCHEMA_NOT_READY', 'Tb_FileAttach table is missing', 503);
            exit;
        }

        $attachIds = FmsInputValidator::idxList($_POST['idx_list'] ?? ($_POST['attach_idx'] ?? ($_POST['idx'] ?? '')));
        if ($attachIds === []) {
            ApiResponse::error('INVALID_PARAM', 'attach idx_list is required', 422);
            exit;
        }

        $ph = implode(',', array_fill(0, count($attachIds), '?'));

        if ($columnExists($db, 'Tb_FileAttach', 'is_deleted')) {
            $setSql = ['is_deleted = 1'];
            if ($columnExists($db, 'Tb_FileAttach', 'deleted_at')) { $setSql[] = 'deleted_at = GETDATE()'; }
            if ($columnExists($db, 'Tb_FileAttach', 'deleted_by')) { $setSql[] = 'deleted_by = ' . (int)($context['user_pk'] ?? 0); }
            $stmt = $db->prepare('UPDATE Tb_FileAttach SET ' . implode(', ', $setSql) . " WHERE idx IN ({$ph})");
            $stmt->execute($attachIds);
            $affected = (int)$stmt->rowCount();
        } else {
            $stmt = $db->prepare("DELETE FROM Tb_FileAttach WHERE idx IN ({$ph})");
            $stmt->execute($attachIds);
            $affected = (int)$stmt->rowCount();
        }

        ApiResponse::success([
            'idx_list' => $attachIds,
            'deleted_count' => $affected,
        ], 'OK', '첨부 삭제 성공');
        exit;
    }

    if ($todo === 'delete') {
        if ($roleLevel < 1 || $roleLevel > 4) {
            ApiResponse::error('FORBIDDEN', 'insufficient role level', 403, ['required' => 4, 'current' => $roleLevel]);
            exit;
        }

        $idxList = FmsInputValidator::idxList($_POST['idx_list'] ?? ($_POST['idx'] ?? ''));
        if ($idxList === []) {
            ApiResponse::error('INVALID_PARAM', 'idx_list is required', 422);
            exit;
        }

        $ph = implode(',', array_fill(0, count($idxList), '?'));

        try {
            $db->beginTransaction();

            $mode = 'hard';
            $deleted = 0;
            $linkedMembersDeleted = 0;

            if ($hasSoftDelete) {
                $mode = 'soft';
                $setSql = ['is_deleted = 1'];
                $params = [];

                if ($columnExists($db, 'Tb_HeadOffice', 'deleted_at')) {
                    $setSql[] = 'deleted_at = GETDATE()';
                }
                if ($columnExists($db, 'Tb_HeadOffice', 'deleted_by')) {
                    $setSql[] = 'deleted_by = ?';
                    $params[] = (int)($context['user_pk'] ?? 0);
                }

                $params = array_merge($params, $idxList);
                $stmt = $db->prepare('UPDATE Tb_HeadOffice SET ' . implode(', ', $setSql) . " WHERE idx IN ({$ph})");
                $stmt->execute($params);
                $deleted = (int)$stmt->rowCount();
            } else {
                if ($tableExists($db, 'Tb_Members') && $columnExists($db, 'Tb_Members', 'head_idx')) {
                    $memberWhere = "head_idx IN ({$ph})";
                    if ($columnExists($db, 'Tb_Members', 'member_status')) {
                        $memberWhere .= " AND member_status = N'예정'";
                    }
                    $memberStmt = $db->prepare('DELETE FROM Tb_Members WHERE ' . $memberWhere);
                    $memberStmt->execute($idxList);
                    $linkedMembersDeleted = (int)$memberStmt->rowCount();
                }

                $stmt = $db->prepare("DELETE FROM Tb_HeadOffice WHERE idx IN ({$ph})");
                $stmt->execute($idxList);
                $deleted = (int)$stmt->rowCount();
            }

            $db->commit();

            $writeSvcAuditLog($db, $tableExists, $columnExists, 'HeadOffice.php', 'delete', [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'target_table' => 'Tb_HeadOffice',
                'target_idx' => $idxList[0] ?? 0,
                'actor_user_pk' => (int)($context['user_pk'] ?? 0),
                'actor_login_id' => (string)($context['login_id'] ?? ''),
                'status' => 'SUCCESS',
                'message' => 'head office deleted',
                'idx_list' => $idxList,
            ]);
            $shadowId = $enqueueShadow($db, $security, $context, 'delete', 'Tb_HeadOffice', $idxList[0] ?? 0, ['idx_list' => $idxList]);

            ApiResponse::success([
                'idx_list' => $idxList,
                'requested_count' => count($idxList),
                'deleted_count' => $deleted,
                'linked_member_deleted_count' => $linkedMembersDeleted,
                'delete_mode' => $mode,
                'shadow_queue_idx' => $shadowId,
            ], 'OK', '본사 삭제 성공');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    ApiResponse::error('UNSUPPORTED_TODO', '지원하지 않는 todo: ' . $todoRaw, 400, ['todo' => $todoRaw]);
} catch (InvalidArgumentException $e) {
    ApiResponse::error('INVALID_PARAM', $e->getMessage(), 422);
} catch (Throwable $e) {
    ApiResponse::error(
        'SERVER_ERROR',
        shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : '서버 오류가 발생했습니다',
        500
    );
}
