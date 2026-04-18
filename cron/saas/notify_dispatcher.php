<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../dist_library/saas/ShadowWriteQueueService.php';

date_default_timezone_set((string)shvEnv('APP_TIMEZONE', 'Asia/Seoul'));

/* ── 중복 실행 방지 (flock 기반 락) ── */
$_lockFile = sys_get_temp_dir() . '/shvq_notify_dispatcher.lock';
$_lockFp   = fopen($_lockFile, 'c');
if (!$_lockFp || !flock($_lockFp, LOCK_EX | LOCK_NB)) {
    echo json_encode(['status' => 'already_running']);
    exit(0);
}
register_shutdown_function(static function () use ($_lockFp, $_lockFile): void {
    flock($_lockFp, LOCK_UN);
    fclose($_lockFp);
    @unlink($_lockFile);
});

try {
    $security = require __DIR__ . '/../../config/security.php';
    $opts = getopt('', [
        'stale-minutes::',
        'threshold::',
        'older-than-minutes::',
        'service-code::',
        'tenant-id::',
        'dry-run::',
    ]);

    $staleMinutes = isset($opts['stale-minutes'])
        ? max(1, (int)$opts['stale-minutes'])
        : max(1, (int)($security['shadow_write']['monitor_stale_retrying_minutes'] ?? 20));

    $threshold = isset($opts['threshold'])
        ? max(1, (int)$opts['threshold'])
        : max(1, (int)($security['shadow_write']['monitor_backlog_threshold'] ?? 100));

    $olderThanMinutes = isset($opts['older-than-minutes'])
        ? max(0, (int)$opts['older-than-minutes'])
        : max(0, (int)($security['shadow_write']['monitor_backlog_older_minutes'] ?? 10));

    $serviceCode = trim((string)($opts['service-code'] ?? ''));
    $tenantId = max(0, (int)($opts['tenant-id'] ?? 0));
    $dryRun = isset($opts['dry-run']);

    $db = DbConnection::get();
    $queue = new ShadowWriteQueueService($db, $security);
    $audit = new AuditLogger($db, $security);

    $recoveredCount = 0;
    if (!$dryRun) {
        $recoveredCount = $queue->recoverStaleRetrying($staleMinutes);
    }

    $openCount = $queue->openCountOlderThan($olderThanMinutes);
    $stats = $queue->stats([
        'service_code' => $serviceCode,
        'tenant_id' => $tenantId,
    ]);

    $alertTriggered = $openCount >= $threshold;
    if ($alertTriggered) {
        $audit->log(
            'shadow.queue.alert',
            0,
            'WARN',
            'Shadow queue backlog threshold exceeded',
            [
                'open_count' => $openCount,
                'threshold' => $threshold,
                'older_than_minutes' => $olderThanMinutes,
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
                'stale_recovered_count' => $recoveredCount,
            ]
        );
    }

    echo json_encode([
        'ok' => true,
        'code' => 'OK',
        'message' => 'notify_dispatcher completed',
        'data' => [
            'dry_run' => $dryRun,
            'stale_minutes' => $staleMinutes,
            'threshold' => $threshold,
            'older_than_minutes' => $olderThanMinutes,
            'scope' => [
                'service_code' => $serviceCode,
                'tenant_id' => $tenantId,
            ],
            'recovered_count' => $recoveredCount,
            'open_count' => $openCount,
            'alert_triggered' => $alertTriggered,
            'stats' => $stats,
            'executed_at' => date('Y-m-d H:i:s'),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'code' => 'DISPATCHER_ERROR',
        'message' => shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : 'dispatcher failed',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
