<?php
declare(strict_types=1);

/**
 * mail_sync_cron.php (Phase 4)
 * - 활성 메일 계정 증분 동기화
 * - 배치 200 (기본)
 * - 최근 24시간 로그인 사용자 계정만 대상
 * - Redis online(userPk) 제외
 * - 오래된 last_synced_at 우선
 * 실행 예시:
 *   php mail_sync_cron.php --folder=INBOX --limit=200
 *   php mail_sync_cron.php --dry-run=1 --limit=20
 *
 * Windows Task Scheduler:
 *   php D:\SHV_ERP\SHVQ_V2\cron\saas\mail_sync_cron.php
 */

// CLI 전용
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$cronRoot = dirname(__DIR__, 2); // SHVQ_V2/
require_once $cronRoot . '/config/env.php';
require_once $cronRoot . '/dist_library/saas/security/init.php';
require_once $cronRoot . '/dist_library/mail/MailboxService.php';

/* ── 중복 실행 방지 (flock 기반 락) ── */
$lockFile = sys_get_temp_dir() . '/shvq_mail_sync.lock';
$lockFp   = fopen($lockFile, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo '[' . date('Y-m-d H:i:s') . '] 이미 실행 중. 종료.' . PHP_EOL;
    exit(0);
}
register_shutdown_function(static function () use ($lockFp, $lockFile): void {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    @unlink($lockFile);
});

use SHVQ\Mail\MailboxService;

function out(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function tableNameOnly(string $name): string
{
    $trim = trim(str_replace(['[', ']'], '', $name));
    if ($trim === '') {
        return '';
    }
    $parts = explode('.', $trim);
    return trim((string)end($parts));
}

function tableSchemaOnly(string $name): ?string
{
    $trim = trim(str_replace(['[', ']'], '', $name));
    if ($trim === '') {
        return null;
    }
    $parts = explode('.', $trim);
    if (count($parts) <= 1) {
        return null;
    }
    return trim((string)$parts[count($parts) - 2]) ?: null;
}

function quoteSqlIdent(string $name): string
{
    $trim = trim(str_replace(['[', ']'], '', $name));
    if ($trim === '') {
        throw new InvalidArgumentException('empty SQL identifier');
    }
    if (!preg_match('/^[A-Za-z0-9_\\.]+$/', $trim)) {
        throw new InvalidArgumentException('unsafe SQL identifier: ' . $name);
    }
    $parts = explode('.', $trim);
    $quoted = array_map(
        static fn(string $part): string => '[' . $part . ']',
        array_filter($parts, static fn(string $p): bool => $p !== '')
    );
    return implode('.', $quoted);
}

function tableExists(PDO $db, string $table): bool
{
    $tableName = tableNameOnly($table);
    if ($tableName === '') {
        return false;
    }
    $schema = tableSchemaOnly($table);
    $sql = "
        SELECT TOP 1 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_NAME = :table_name
    ";
    if ($schema !== null) {
        $sql .= " AND TABLE_SCHEMA = :table_schema";
    }
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':table_name', $tableName);
    if ($schema !== null) {
        $stmt->bindValue(':table_schema', $schema);
    }
    $stmt->execute();
    return (bool)$stmt->fetchColumn();
}

function columnExists(PDO $db, string $table, string $column): bool
{
    $tableName = tableNameOnly($table);
    if ($tableName === '') {
        return false;
    }
    $schema = tableSchemaOnly($table);
    $sql = "
        SELECT TOP 1 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ";
    if ($schema !== null) {
        $sql .= " AND TABLE_SCHEMA = :table_schema";
    }
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':table_name', $tableName);
    $stmt->bindValue(':column_name', $column);
    if ($schema !== null) {
        $stmt->bindValue(':table_schema', $schema);
    }
    $stmt->execute();
    return (bool)$stmt->fetchColumn();
}

/**
 * Redis mail:online:<userPk> 키를 스캔해 온라인 user_pk 목록을 수집한다.
 */
function resolveOnlineUserPks(): array
{
    if (!class_exists('Redis')) {
        return [];
    }

    $host = trim((string)(getenv('REDIS_HOST') ?: '127.0.0.1'));
    $port = (int)(getenv('REDIS_PORT') ?: 6379);
    $timeout = 1.5;

    try {
        $redis = new Redis();
        $connected = @$redis->connect($host, $port, $timeout);
        if (!$connected) {
            return [];
        }

        $online = [];
        $it = null;
        do {
            $keys = $redis->scan($it, 'mail:online:*', 200);
            if (is_array($keys)) {
                foreach ($keys as $key) {
                    if (!is_string($key)) {
                        continue;
                    }
                    if (preg_match('/^mail:online:(\d+)$/', $key, $m)) {
                        $pk = (int)$m[1];
                        if ($pk > 0) {
                            $online[$pk] = true;
                        }
                    }
                }
            }
        } while ($it !== 0);

        try {
            $redis->close();
        } catch (Throwable) {
            // noop
        }

        return array_map('intval', array_keys($online));
    } catch (Throwable) {
        return [];
    }
}

$startTime = microtime(true);

// CLI 옵션 파싱
$opts   = getopt('', ['folder::', 'limit::', 'dry-run::']);
$folder = trim((string)($opts['folder'] ?? 'INBOX'));
if ($folder === '') {
    $folder = 'INBOX';
}
$accountLimit = max(1, min(500, (int)($opts['limit'] ?? 200))); // 기본 200
$dryRunRaw = (string)($opts['dry-run'] ?? '0');
$dryRun = in_array(strtolower($dryRunRaw), ['1', 'true', 'yes', 'y', 'on'], true);

$db = DbConnection::get();
$security = require $cronRoot . '/config/security.php';

try {
    if (!tableExists($db, 'Tb_IntProviderAccount')) {
        throw new RuntimeException('Tb_IntProviderAccount 테이블이 없습니다.');
    }

    $hasSyncState = tableExists($db, 'Tb_Mail_FolderSyncState');
    $onlineUserPks = resolveOnlineUserPks();

    $join = '';
    $where = [
        "a.provider = 'mail'",
        "ISNULL(a.status, 'ACTIVE') = 'ACTIVE'",
    ];
    $params = [':folder' => $folder];
    $filterApplied = [];

    if ($hasSyncState) {
        $join .= " LEFT JOIN dbo.Tb_Mail_FolderSyncState fs ON fs.account_idx = a.idx AND fs.folder = :folder";
    }

    // 24시간 로그인 필터: 사용자 테이블 last_login_at 우선, 없으면 provider account에 컬럼이 있을 때 적용
    $authTable = (string)($security['auth']['user_table'] ?? 'Tb_Users');
    $authPkCol = (string)($security['auth']['user_pk_column'] ?? 'idx');

    if (tableExists($db, $authTable) && columnExists($db, $authTable, 'last_login_at')) {
        $authTableSql = quoteSqlIdent($authTable);
        $authPkColSql = quoteSqlIdent($authPkCol);
        $join .= " INNER JOIN {$authTableSql} u ON u.{$authPkColSql} = a.user_pk";
        $where[] = "u.last_login_at > DATEADD(day, -1, GETDATE())";
        $filterApplied[] = 'user.last_login_at(24h)';
    } elseif (columnExists($db, 'Tb_IntProviderAccount', 'last_login_at')) {
        $where[] = "a.last_login_at > DATEADD(day, -1, GETDATE())";
        $filterApplied[] = 'provider.last_login_at(24h)';
    } else {
        $filterApplied[] = 'last_login_at(filter unavailable)';
    }

    if ($onlineUserPks !== []) {
        $onlinePlaceholders = [];
        $idx = 0;
        foreach (array_values(array_unique($onlineUserPks)) as $pk) {
            if ($pk <= 0) {
                continue;
            }
            $ph = ':online_pk_' . $idx++;
            $onlinePlaceholders[] = $ph;
            $params[$ph] = (int)$pk;
        }
        if ($onlinePlaceholders !== []) {
            $where[] = 'a.user_pk NOT IN (' . implode(', ', $onlinePlaceholders) . ')';
            $filterApplied[] = 'online-excluded(' . count($onlinePlaceholders) . ')';
        }
    } else {
        $filterApplied[] = 'online-excluded(0)';
    }

    $selectLastSynced = $hasSyncState ? 'fs.last_synced_at' : 'CAST(NULL AS DATETIME) AS last_synced_at';

    $sql = "
        SELECT TOP ({$accountLimit})
               a.idx AS account_idx,
               a.service_code,
               a.tenant_id,
               a.user_pk,
               {$selectLastSynced}
        FROM dbo.Tb_IntProviderAccount a
        {$join}
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ISNULL(" . ($hasSyncState ? 'fs.last_synced_at' : 'NULL') . ", '2000-01-01') ASC
    ";

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    out('Cron 대상 필터: ' . implode(', ', $filterApplied));
} catch (Throwable $e) {
    out('DB 오류: ' . $e->getMessage());
    exit(1);
}

if (empty($accounts)) {
    out('동기화할 계정 없음.');
    exit(0);
}

$mailboxService = new MailboxService($db);

if ($dryRun) {
    out('DRY RUN 모드 — 실제 mailSync는 수행하지 않습니다.');
    out('선정 계정 수=' . count($accounts) . ' limit=' . $accountLimit . ' folder=' . $folder);
    foreach ($accounts as $acc) {
        out('target account_idx=' . (int)$acc['account_idx']
            . ' user_pk=' . (int)($acc['user_pk'] ?? 0)
            . ' service=' . (string)($acc['service_code'] ?? 'shvq')
            . ' tenant=' . (int)($acc['tenant_id'] ?? 0)
            . ' last_synced_at=' . (string)($acc['last_synced_at'] ?? 'NULL')
        );
    }
    $elapsed = round(microtime(true) - $startTime, 2);
    out('DRY RUN 완료 — 소요=' . $elapsed . 's');
    exit(0);
}

$totalSynced  = 0;
$totalSkipped = 0;
$errorCount   = 0;

foreach ($accounts as $acc) {
    $accountIdx  = (int)$acc['account_idx'];
    $serviceCode = (string)($acc['service_code'] ?? 'shvq');
    $tenantId    = (int)($acc['tenant_id'] ?? 0);

    try {
        $result = $mailboxService->mailSync($serviceCode, $tenantId, $accountIdx, [
            'folder' => $folder,
        ]);

        $synced  = (int)($result['synced'] ?? 0);
        $skipped = (int)($result['skipped'] ?? 0);
        $totalSynced  += $synced;
        $totalSkipped += $skipped;

        if ($synced > 0) {
            out('account_idx=' . $accountIdx . ' 신규=' . $synced . '건 스킵=' . $skipped . '건');
        }
    } catch (Throwable $e) {
        $errorCount++;
        out('account_idx=' . $accountIdx . ' 오류: ' . $e->getMessage());
    }
}

$elapsed = round(microtime(true) - $startTime, 2);
out('완료 — 계정=' . count($accounts)
    . ' 신규=' . $totalSynced . '건 스킵=' . $totalSkipped . '건 오류=' . $errorCount . '건 소요=' . $elapsed . 's');
