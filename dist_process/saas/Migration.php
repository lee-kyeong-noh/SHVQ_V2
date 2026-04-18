<?php
declare(strict_types=1);
/**
 * SHVQ V2 — Migration API
 *
 * todo=run_wave12_estimate_bill  POST
 *
 * 목적:
 * - IIS 보안 정책으로 /scripts 직접 URL 실행이 차단된 환경에서
 * - SaaS 인증/CSRF/권한 체크 후 wave12 견적/수금 마이그레이션 실행
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
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

    $todoRaw = trim((string)($_GET['todo'] ?? $_POST['todo'] ?? 'run_wave12_estimate_bill'));
    $todo = strtolower($todoRaw);
    $runTodos = [
        'run_wave12_estimate_bill',
        'run_wave12_estimate_bill_migration',
        'wave12_estimate_bill_migration',
        'run_estimate_bill_migration',
    ];

    if (!in_array($todo, $runTodos, true)) {
        ApiResponse::error('UNSUPPORTED_TODO', '지원하지 않는 todo: ' . $todoRaw, 400, ['todo' => $todoRaw]);
        exit;
    }

    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        ApiResponse::error('METHOD_NOT_ALLOWED', 'POST method required', 405);
        exit;
    }
    if (!$auth->validateCsrf()) {
        ApiResponse::error('CSRF_TOKEN_INVALID', '보안 검증 실패', 403);
        exit;
    }

    $roleLevel = (int)($context['role_level'] ?? 0);
    if ($roleLevel < 1 || $roleLevel > 2) {
        ApiResponse::error('FORBIDDEN', '권한이 부족합니다', 403, ['required_max' => 2, 'current' => $roleLevel]);
        exit;
    }

    $db = DbConnection::get();
    $security = require __DIR__ . '/../../config/security.php';

    $tableExistsCache = [];
    $tableExists = static function (PDO $pdo, string $table) use (&$tableExistsCache): bool {
        $key = strtolower($table);
        if (array_key_exists($key, $tableExistsCache)) {
            return (bool)$tableExistsCache[$key];
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'");
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

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        $exists = (int)$stmt->fetchColumn() > 0;
        $columnExistsCache[$key] = $exists;
        return $exists;
    };

    $writeSvcAuditLog = static function (
        PDO $pdo,
        callable $tableExistsFn,
        callable $columnExistsFn,
        string $todoName,
        array $payload
    ): void {
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

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payloadJson)) {
            $payloadJson = '{}';
        }

        $add('service_code', (string)($payload['service_code'] ?? 'shvq'));
        $add('tenant_id', (int)($payload['tenant_id'] ?? 0));
        $add('api_name', 'Migration.php');
        $add('todo', $todoName);
        $add('target_table', 'MigrationWave12EstimateBill');
        $add('target_idx', 0);
        $add('actor_user_pk', (int)($payload['actor_user_pk'] ?? 0));
        $add('actor_login_id', (string)($payload['actor_login_id'] ?? ''));
        $add('status', (string)($payload['status'] ?? 'SUCCESS'));
        $add('message', (string)($payload['message'] ?? 'migration executed'));
        $add('detail_json', $payloadJson);
        $add('created_at', date('Y-m-d H:i:s'));
        $add('regdate', date('Y-m-d H:i:s'));

        if ($columns === []) {
            return;
        }

        $ph = implode(', ', array_fill(0, count($columns), '?'));
        $stmt = $pdo->prepare('INSERT INTO Tb_SvcAuditLog (' . implode(', ', $columns) . ') VALUES (' . $ph . ')');
        $stmt->execute($values);
    };

    $enqueueShadow = static function (PDO $pdo, array $securityCfg, array $ctx, string $todoName, array $payload): int {
        try {
            $svc = new ShadowWriteQueueService($pdo, $securityCfg);
            return $svc->enqueueJob([
                'service_code' => (string)($ctx['service_code'] ?? 'shvq'),
                'tenant_id' => (int)($ctx['tenant_id'] ?? 0),
                'api' => 'dist_process/saas/Migration.php',
                'todo' => $todoName,
                'target_table' => 'MigrationWave12EstimateBill',
                'target_idx' => 0,
                'actor_user_pk' => (int)($ctx['user_pk'] ?? 0),
                'actor_login_id' => (string)($ctx['login_id'] ?? ''),
                'requested_at' => date('c'),
                'payload' => $payload,
            ], 'PENDING', 0);
        } catch (Throwable) {
            return 0;
        }
    };

    $runnerPath = realpath(__DIR__ . '/../../scripts/run_migration_wave12_estimate_bill.php');
    if (!is_string($runnerPath) || $runnerPath === '' || !is_file($runnerPath)) {
        ApiResponse::error('RUNNER_NOT_FOUND', '마이그레이션 러너 파일이 없습니다', 503);
        exit;
    }

    $backupGet = $_GET;
    $backupPost = $_POST;
    $backupRequestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

    $_GET['token'] = 'shvq_migrate_wave12_estimate_bill';
    $_POST['token'] = 'shvq_migrate_wave12_estimate_bill';
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $runnerError = null;
    ob_start();
    try {
        include $runnerPath;
    } catch (Throwable $e) {
        $runnerError = $e;
    }
    $runnerOutput = trim((string)ob_get_clean());

    $_GET = $backupGet;
    $_POST = $backupPost;
    $_SERVER['REQUEST_METHOD'] = $backupRequestMethod;

    if ($runnerError instanceof Throwable) {
        throw $runnerError;
    }

    $runnerData = json_decode($runnerOutput, true);
    if (!is_array($runnerData)) {
        ApiResponse::error('RUNNER_OUTPUT_INVALID', '마이그레이션 결과 파싱 실패', 500, [
            'output' => mb_substr($runnerOutput, 0, 500),
        ]);
        exit;
    }

    $summary = is_array($runnerData['summary'] ?? null) ? $runnerData['summary'] : [];
    $results = is_array($runnerData['results'] ?? null) ? $runnerData['results'] : [];
    $isOk = (bool)($runnerData['ok'] ?? false);

    $auditPayload = [
        'service_code' => (string)($context['service_code'] ?? 'shvq'),
        'tenant_id' => (int)($context['tenant_id'] ?? 0),
        'actor_user_pk' => (int)($context['user_pk'] ?? 0),
        'actor_login_id' => (string)($context['login_id'] ?? ''),
        'status' => $isOk ? 'SUCCESS' : 'FAILED',
        'message' => $isOk ? 'wave12 migration executed' : 'wave12 migration failed',
        'summary' => $summary,
    ];

    $writeSvcAuditLog($db, $tableExists, $columnExists, 'run_wave12_estimate_bill', $auditPayload);
    $queueId = $enqueueShadow($db, $security, $context, 'run_wave12_estimate_bill', [
        'ok' => $isOk,
        'summary' => $summary,
    ]);

    if ($isOk) {
        DevLogService::tryLog(
            'FMS',
            'Migration wave12 estimate/bill',
            'Wave12 estimate/bill migration executed via SaaS API',
            1
        );

        ApiResponse::success([
            'queue_id' => $queueId,
            'summary' => $summary,
            'results' => $results,
            'source_db' => (string)($runnerData['source_db'] ?? 'CSM_C004732'),
            'target_db' => (string)($runnerData['target_db'] ?? 'CSM_C004732_V2'),
        ], 'OK', 'Wave12 견적/수금 마이그레이션 실행 완료');
        exit;
    }

    DevLogService::tryLog(
        'FMS',
        'Migration wave12 estimate/bill failed',
        'Wave12 estimate/bill migration failed via SaaS API',
        0
    );

    ApiResponse::error('MIGRATION_FAILED', 'Wave12 마이그레이션 실행 중 오류가 발생했습니다', 500, [
        'queue_id' => $queueId,
        'summary' => $summary,
        'results' => $results,
        'source_db' => (string)($runnerData['source_db'] ?? 'CSM_C004732'),
        'target_db' => (string)($runnerData['target_db'] ?? 'CSM_C004732_V2'),
    ]);
} catch (Throwable $e) {
    error_log('[Migration API] ' . $e->getMessage());
    ApiResponse::error(
        'SERVER_ERROR',
        shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : '서버 오류가 발생했습니다',
        500
    );
}
