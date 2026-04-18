<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
if ($token === '' && PHP_SAPI === 'cli') {
    $argv = $_SERVER['argv'] ?? [];
    foreach ((array)$argv as $arg) {
        $v = trim((string)$arg);
        if (str_starts_with($v, '--token=')) {
            $token = substr($v, 8);
            break;
        }
    }
    if ($token === '' && isset($argv[1])) {
        $token = trim((string)$argv[1]);
    }
}
if ($token !== 'shvq_migrate_wave12_estimate_bill') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$root = dirname(__DIR__);
if (!is_file($root . '/config/env.php')) {
    $root = dirname(__DIR__, 2);
}
require_once $root . '/config/env.php';
require_once $root . '/dist_library/saas/security/init.php';

$db = DbConnection::get();
$sourceDb = 'CSM_C004732';
$tables = [
    'Tb_SiteEstimateG',
    'Tb_SiteEstimate',
    'Tb_EstimateItem',
    'Tb_BillGroup',
    'Tb_Bill',
];

$qi = static function (string $name): string {
    return '[' . str_replace(']', ']]', $name) . ']';
};

$dbExists = static function (PDO $pdo, string $dbName): bool {
    $st = $pdo->prepare('SELECT CASE WHEN DB_ID(?) IS NULL THEN 0 ELSE 1 END');
    $st->execute([$dbName]);
    return ((int)$st->fetchColumn()) === 1;
};

$tableExists = static function (PDO $pdo, string $dbName, string $tableName, callable $qiFn): bool {
    $sql = 'SELECT COUNT(*) FROM ' . $qiFn($dbName) . ".INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME=?";
    $st = $pdo->prepare($sql);
    $st->execute([$tableName]);
    return ((int)$st->fetchColumn()) > 0;
};

$targetTableExists = static function (PDO $pdo, string $tableName): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME=?");
    $st->execute([$tableName]);
    return ((int)$st->fetchColumn()) > 0;
};

$columnList = static function (PDO $pdo, string $dbName, string $tableName, callable $qiFn): array {
    $sql = 'SELECT COLUMN_NAME FROM ' . $qiFn($dbName) . ".INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME=? ORDER BY ORDINAL_POSITION";
    $st = $pdo->prepare($sql);
    $st->execute([$tableName]);
    $rows = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!is_array($rows)) {
        return [];
    }
    $cols = [];
    foreach ($rows as $col) {
        $name = trim((string)$col);
        if ($name !== '') {
            $cols[] = $name;
        }
    }
    return $cols;
};

$targetColumnList = static function (PDO $pdo, string $tableName): array {
    $st = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME=? ORDER BY ORDINAL_POSITION");
    $st->execute([$tableName]);
    $rows = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!is_array($rows)) {
        return [];
    }
    $cols = [];
    foreach ($rows as $col) {
        $name = trim((string)$col);
        if ($name !== '') {
            $cols[] = $name;
        }
    }
    return $cols;
};

$rowCount = static function (PDO $pdo, string $tableRef): int {
    $st = $pdo->query('SELECT COUNT(*) FROM ' . $tableRef);
    return (int)($st ? $st->fetchColumn() : 0);
};

$isIdentityColumn = static function (PDO $pdo, string $tableName, string $column): bool {
    $st = $pdo->prepare("SELECT CASE WHEN COLUMNPROPERTY(OBJECT_ID(?), ?, 'IsIdentity') = 1 THEN 1 ELSE 0 END");
    $st->execute(['dbo.' . $tableName, $column]);
    return ((int)$st->fetchColumn()) === 1;
};

$results = [];
$summary = [
    'tables_total' => count($tables),
    'tables_ok' => 0,
    'tables_error' => 0,
    'rows_inserted_total' => 0,
];

try {
    if (!$dbExists($db, $sourceDb)) {
        throw new RuntimeException('source db not found: ' . $sourceDb);
    }

    foreach ($tables as $tableName) {
        $res = [
            'table' => $tableName,
            'status' => 'ok',
            'created_table' => false,
            'identity_insert_used' => false,
            'source_rows' => 0,
            'target_rows_before' => 0,
            'target_rows_after' => 0,
            'inserted_rows' => 0,
            'message' => '',
        ];

        try {
            if (!$tableExists($db, $sourceDb, $tableName, $qi)) {
                $res['status'] = 'source_missing';
                $res['message'] = 'source table missing';
                $results[] = $res;
                $summary['tables_error']++;
                continue;
            }

            if (!$targetTableExists($db, $tableName)) {
                $createSql = 'SELECT TOP (0) * INTO dbo.' . $qi($tableName) . ' FROM ' . $qi($sourceDb) . '.dbo.' . $qi($tableName);
                $db->exec($createSql);
                $res['created_table'] = true;
            }

            $srcCols = $columnList($db, $sourceDb, $tableName, $qi);
            $tgtCols = $targetColumnList($db, $tableName);
            $tgtMap = array_fill_keys($tgtCols, true);
            $sharedCols = [];
            foreach ($srcCols as $col) {
                if (isset($tgtMap[$col])) {
                    $sharedCols[] = $col;
                }
            }
            if ($sharedCols === []) {
                $res['status'] = 'skip_no_shared_columns';
                $res['message'] = 'no shared columns';
                $results[] = $res;
                $summary['tables_error']++;
                continue;
            }

            $sourceRef = $qi($sourceDb) . '.dbo.' . $qi($tableName);
            $targetRef = 'dbo.' . $qi($tableName);
            $res['source_rows'] = $rowCount($db, $sourceRef);
            $res['target_rows_before'] = $rowCount($db, $targetRef);

            $hasIdx = in_array('idx', $sharedCols, true);
            if (!$hasIdx) {
                $res['status'] = 'skip_no_idx';
                $res['message'] = 'idx column missing, skip to avoid duplicates';
                $res['target_rows_after'] = $res['target_rows_before'];
                $results[] = $res;
                $summary['tables_error']++;
                continue;
            }

            $colSql = implode(', ', array_map($qi, $sharedCols));
            $insertSql = 'INSERT INTO ' . $targetRef . ' (' . $colSql . ') '
                . 'SELECT ' . $colSql . ' FROM ' . $sourceRef . ' s '
                . 'WHERE NOT EXISTS (SELECT 1 FROM ' . $targetRef . ' t WHERE t.[idx]=s.[idx])';

            $useIdentityInsert = $isIdentityColumn($db, $tableName, 'idx');
            if ($useIdentityInsert) {
                $res['identity_insert_used'] = true;
                $db->exec('SET IDENTITY_INSERT ' . $targetRef . ' ON');
                try {
                    $db->exec($insertSql);
                } finally {
                    $db->exec('SET IDENTITY_INSERT ' . $targetRef . ' OFF');
                }
            } else {
                $db->exec($insertSql);
            }

            $res['target_rows_after'] = $rowCount($db, $targetRef);
            $res['inserted_rows'] = max(0, $res['target_rows_after'] - $res['target_rows_before']);
            $summary['rows_inserted_total'] += $res['inserted_rows'];

            if ($useIdentityInsert) {
                $db->exec("DBCC CHECKIDENT ('dbo." . str_replace("'", "''", $tableName) . "', RESEED) WITH NO_INFOMSGS");
            }

            $results[] = $res;
            $summary['tables_ok']++;
        } catch (Throwable $e) {
            $res['status'] = 'error';
            $res['message'] = $e->getMessage();
            $results[] = $res;
            $summary['tables_error']++;
        }
    }

    echo json_encode([
        'ok' => $summary['tables_error'] === 0,
        'source_db' => $sourceDb,
        'target_db' => 'CSM_C004732_V2',
        'summary' => $summary,
        'results' => $results,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'summary' => $summary,
        'results' => $results,
    ], JSON_UNESCAPED_UNICODE);
}
