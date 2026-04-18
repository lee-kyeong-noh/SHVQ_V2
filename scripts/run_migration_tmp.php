<?php
declare(strict_types=1);
/* 일회성 마이그레이션 실행기 — 실행 후 즉시 삭제 */
if (($_GET['tk'] ?? '') !== 'shvq_mig_20260413') { http_response_code(403); exit('forbidden'); }

require_once __DIR__ . '/../config/env.php';

$host   = shvEnv('DB_HOST', '211.116.112.67');
$port   = shvEnv('DB_PORT', '1433');
$dbname = 'CSM_C004732_V2';
$user   = shvEnv('DB_USER', 'jjd');
$pass   = shvEnv('DB_PASS', 'dlrudfh0');

$dsn = "sqlsrv:Server={$host},{$port};Database={$dbname};Encrypt=0;TrustServerCertificate=1;LoginTimeout=30";
try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    die(json_encode(['ok' => false, 'error' => 'DB_CONNECT', 'message' => $e->getMessage()]));
}

$sqlFile = __DIR__ . '/migrations/20260413_wave2_mail_account_v1_to_intprovider.sql';
$raw = file_get_contents($sqlFile);
if ($raw === false) {
    die(json_encode(['ok' => false, 'error' => 'FILE_READ', 'message' => 'SQL file not found']));
}

/* GO 기준으로 배치 분리 */
$batches = preg_split('/^\s*GO\s*$/mi', $raw);
$results = [];
$lastRows = null;

foreach ($batches as $batch) {
    $batch = trim($batch);
    if ($batch === '' || $batch === ';') continue;

    try {
        $stmt = $pdo->query($batch);
        if ($stmt === false) continue;

        /* 마지막 SELECT 결과셋 수집 */
        do {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $lastRows = $rows;
            }
        } while ($stmt->nextRowset());

        $results[] = ['ok' => true, 'batch_len' => strlen($batch)];
    } catch (PDOException $e) {
        /* RAISERROR(severity 16)는 예외로 잡힘 */
        $results[] = ['ok' => false, 'error' => $e->getMessage()];
        /* STOP 조건이면 여기서 중단 */
        if (str_contains($e->getMessage(), '[STOP]')) {
            break;
        }
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok'      => true,
    'batches' => count($results),
    'errors'  => array_filter($results, fn($r) => !$r['ok']),
    'result'  => $lastRows,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

/* 자기 자신 삭제 */
@unlink(__FILE__);
