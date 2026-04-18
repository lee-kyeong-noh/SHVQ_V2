<?php
declare(strict_types=1);
if (($_GET['tk'] ?? '') !== 'shvq_mig_20260413') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/env.php';
$dsn = "sqlsrv:Server=".shvEnv('DB_HOST').",".shvEnv('DB_PORT').";Database=CSM_C004732_V2;Encrypt=0;TrustServerCertificate=1";
$pdo = new PDO($dsn, shvEnv('DB_USER'), shvEnv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

/* 이경노 / no1 찾기 */
$me = $pdo->query("SELECT idx, name, id, authority_idx, user_type FROM dbo.Tb_Users WHERE id='no1' OR name LIKE '%이경노%'")->fetchAll(PDO::FETCH_ASSOC);

/* authority_idx 분포 */
$dist = $pdo->query("SELECT authority_idx, COUNT(1) AS cnt FROM dbo.Tb_Users GROUP BY authority_idx ORDER BY authority_idx DESC")->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['me' => $me, 'authority_distribution' => $dist], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
@unlink(__FILE__);
